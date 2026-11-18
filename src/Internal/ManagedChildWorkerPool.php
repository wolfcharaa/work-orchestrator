<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Worker;
use Amp\Sync\ChannelException;
use Fedor\WorkOrchestrator\ChildEventRoute;
use Fedor\WorkOrchestrator\Contract\ApplicationEvent;
use Fedor\WorkOrchestrator\Contract\IdempotentManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Contract\RoutesChildApplicationEvents;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Exception\WorkerCleanupFailed;
use Fedor\WorkOrchestrator\Internal\Task\ExecuteManagedTask;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Result\TaskStatus;
use Fedor\WorkOrchestrator\WorkerCrashAction;
use LogicException;
use Throwable;

/** Reusable, bounded depth-two process executor for one primary. @internal */
final class ManagedChildWorkerPool implements ManagedTaskExecutor
{
    /** @var list<Worker> */
    private array $workers = [];

    /** @var list<Worker> */
    private array $available = [];

    private readonly BoundedSlotGate $slots;

    private bool $closed = false;

    public function __construct(
        private readonly int $workerLimit,
        private readonly ?ManagedTaskBootstrap $bootstrap,
        /** @var RoutesChildApplicationEvents<mixed, mixed>|null */
        private readonly ?RoutesChildApplicationEvents $eventRouter,
        private readonly ManagedTaskCrashPolicy $crashPolicy,
        private readonly int $maxPayloadBytes,
        private readonly float $cancellationGraceSeconds,
    ) {
        $this->slots = new BoundedSlotGate($this->workerLimit);
    }

    /** @return Future<TaskOutcome<mixed>> */
    public function submit(
        ManagedTask $task,
        EventContext $context,
        Cancellation $cancellation,
    ): Future {
        if ($this->closed) {
            throw new LogicException('Managed child worker pool is closed.');
        }
        $this->initialize($cancellation);
        $this->slots->acquire($cancellation);
        $worker = \array_shift($this->available);
        if ($worker === null) {
            $this->slots->release();
            throw new LogicException('No managed child worker is available after slot admission.');
        }

        return async(function () use ($task, $context, $worker, $cancellation): TaskOutcome {
            $currentWorker = $worker;
            try {
                return $this->executeWithRecovery(
                    $task,
                    $context,
                    $currentWorker,
                    $cancellation,
                );
            } finally {
                if ($currentWorker->isRunning()) {
                    $this->available[] = $currentWorker;
                }
                $this->slots->release();
            }
        });
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $workers = $this->workers;
        $this->workers = [];
        $this->available = [];
        $failures = ChildWorkerGroupCleanup::run($workers, $this->cancellationGraceSeconds);
        if ($failures !== []) {
            throw new WorkerCleanupFailed(null, $failures);
        }
    }

    public function forceClose(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $workers = $this->workers;
        $this->workers = [];
        $this->available = [];
        foreach ($workers as $worker) {
            $worker->kill();
        }
    }

    /** @return TaskOutcome<mixed> */
    private function executeWithRecovery(
        ManagedTask $task,
        EventContext $context,
        Worker &$worker,
        Cancellation $cancellation,
    ): TaskOutcome {
        $diagnostics = [];

        for ($attempt = 1; $attempt <= $this->crashPolicy->maxAttempts(); ++$attempt) {
            $attemptContext = new EventContext(
                runId: $context->runId,
                workId: $context->workId,
                taskId: $context->taskId,
                parentTaskId: $context->parentTaskId,
                role: $context->role,
                depth: $context->depth,
                sourceId: $context->sourceId . '-worker-attempt-' . $attempt,
            );

            try {
                $execution = $worker->submit(
                    new ExecuteManagedTask($task, $attemptContext, $this->maxPayloadBytes),
                    $cancellation,
                );

                return self::withDiagnostics(
                    $this->awaitExecution($execution, $cancellation),
                    $diagnostics,
                );
            } catch (CancelledException $failure) {
                return TaskOutcome::cancelled(FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::task($attemptContext, ExecutorType::Parallel, $attempt),
                ), $diagnostics);
            } catch (Throwable $failure) {
                $diagnostic = FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::unavailableTask($attemptContext, ExecutorType::Parallel, $attempt),
                    FailureKind::WorkerCrash,
                );
                $action = $this->crashPolicy->onCrash($task, $diagnostic, $attempt);
                $retry = $action === WorkerCrashAction::RetryOnFreshWorker
                    && $attempt < $this->crashPolicy->maxAttempts();
                if (!$retry) {
                    return TaskOutcome::failed($diagnostic, $diagnostics);
                }
                if (!$task instanceof IdempotentManagedTask) {
                    return TaskOutcome::failed(
                        FailureDiagnostic::from(new LogicException(\sprintf(
                            'Managed task crash retry for %s requires %s.',
                            $task::class,
                            IdempotentManagedTask::class,
                        ))),
                        [...$diagnostics, $diagnostic],
                    );
                }

                $diagnostics[] = $diagnostic;
                try {
                    $worker = $this->replace($worker, $cancellation);
                } catch (Throwable $replacementFailure) {
                    return TaskOutcome::failed(
                        FailureDiagnostic::from($replacementFailure),
                        $diagnostics,
                    );
                }
            }
        }

        throw new LogicException('Managed task crash loop ended without an outcome.');
    }

    private function replace(Worker $worker, Cancellation $cancellation): Worker
    {
        $index = \array_search($worker, $this->workers, true);
        if (!\is_int($index)) {
            throw new LogicException('Crashed managed child worker is not registered.');
        }

        $worker->kill();
        $replacement = WorkerGroupFactory::create(1, $cancellation);
        try {
            ChildWorkerRuntimeInitializer::initialize($replacement, $this->bootstrap, $cancellation);
        } catch (Throwable $failure) {
            $replacement[0]->kill();
            throw $failure;
        }

        return $this->workers[$index] = $replacement[0];
    }

    /**
     * @param TaskOutcome<mixed> $outcome
     * @param list<FailureDiagnostic> $diagnostics
     * @return TaskOutcome<mixed>
     */
    private static function withDiagnostics(TaskOutcome $outcome, array $diagnostics): TaskOutcome
    {
        $diagnostics = [...$diagnostics, ...$outcome->diagnostics];

        return match ($outcome->status) {
            TaskStatus::Succeeded => TaskOutcome::succeeded($outcome->value, $diagnostics),
            TaskStatus::Failed => TaskOutcome::failed(
                $outcome->failure ?? throw new LogicException('Failed task has no diagnostic.'),
                $diagnostics,
            ),
            TaskStatus::Cancelled => TaskOutcome::cancelled(
                $outcome->failure ?? throw new LogicException('Cancelled task has no diagnostic.'),
                $diagnostics,
            ),
        };
    }

    private function initialize(Cancellation $cancellation): void
    {
        if ($this->workers !== []) {
            return;
        }

        $workers = WorkerGroupFactory::create($this->workerLimit, $cancellation);
        try {
            ChildWorkerRuntimeInitializer::initialize($workers, $this->bootstrap, $cancellation);
        } catch (Throwable $failure) {
            foreach ($workers as $worker) {
                $worker->kill();
            }

            throw $failure;
        }

        $this->workers = $workers;
        $this->available = $workers;
    }

    /**
     * @param Execution<TaskOutcome<mixed>, never, EventEnvelope> $execution
     * @return TaskOutcome<mixed>
     */
    private function awaitExecution(Execution $execution, Cancellation $cancellation): TaskOutcome
    {
        $drain = async(function () use ($execution, $cancellation): void {
            $channel = $execution->getChannel();

            try {
                while (!$channel->isClosed()) {
                    $message = $channel->receive($cancellation);
                    $this->route($message);
                }
            } catch (ChannelException) {
                // ContextWorker completes the job queue after its terminal result.
            }
        });

        try {
            $outcome = $execution->await($cancellation);
            $drain->await($cancellation);

            return $outcome;
        } catch (Throwable $failure) {
            try {
                $drain->await();
            } catch (Throwable) {
                // The execution failure remains the primary diagnostic.
            }

            throw $failure;
        }
    }

    private function route(EventEnvelope $envelope): void
    {
        if (!$envelope->event instanceof ApplicationEvent) {
            ProcessEvents::relay($envelope);

            return;
        }

        $route = $this->eventRouter?->routeChildApplicationEvent($envelope)
            ?? ChildEventRoute::Relay;
        if ($route === ChildEventRoute::Relay) {
            ProcessEvents::relay($envelope);
        }
    }
}
