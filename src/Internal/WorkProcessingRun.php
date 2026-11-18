<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;

use function Amp\Future\awaitAll;

use Amp\Parallel\Worker\Execution;
use Amp\Sync\ChannelException;
use Fedor\WorkOrchestrator\Contract\DeclaresIdempotentWork;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded;
use Fedor\WorkOrchestrator\Exception\RemoteTaskFailure;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Internal\Task\ExecuteWork;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Result\TaskStatus;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Exhausted;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkerCrashAction;
use LogicException;
use Throwable;

/**
 * Owns root admission, live event drain, and ordered result aggregation.
 *
 * @template TPayload
 * @template TResult
 * @internal
 */
final class WorkProcessingRun
{
    private readonly ProcessingFailure $failure;

    /** @var array<int, WorkResult<mixed>> */
    private array $results = [];

    /** @var list<FailureDiagnostic> */
    private array $diagnostics = [];

    private bool $cancelled = false;

    private bool $failed = false;

    /**
     * @param non-empty-string                  $runId
     * @param WorkQueue<TPayload> $queue
     * @param Strategy<TPayload, TResult> $strategy
     * @param WorkerCrashPolicy<TPayload> $workerCrashPolicy
     * @param HookDispatcher<TPayload> $hooks
     */
    public function __construct(
        private readonly string $runId,
        private readonly WorkQueue $queue,
        private readonly int $maxPayloadBytes,
        private readonly HookDispatcher $hooks,
        private readonly Strategy $strategy,
        private readonly WorkerCrashPolicy $workerCrashPolicy,
        ?Cancellation $cancellation,
    ) {
        $this->failure = new ProcessingFailure($cancellation);
    }

    /** @param PrimaryWorkerRegistry<TPayload, TResult> $workers */
    public function run(PrimaryWorkerRegistry $workers): ProcessingResult
    {
        /** @var array<int, Future<void>> $futures */
        $futures = [];
        foreach ($workers->indexes() as $index) {
            $futures[$index] = async($this->processWorker(...), $workers, $index);
        }

        [$errors] = awaitAll($futures);
        foreach ($errors as $failure) {
            if ($failure instanceof CancelledException) {
                $this->cancelled = true;
            }
            $this->diagnostics[] = FailureDiagnostic::from($failure);
            $this->failed = true;
        }

        \ksort($this->results);

        return new ProcessingResult(
            \array_values($this->results),
            $this->diagnostics,
            $this->cancelled,
            $this->failed,
        );
    }

    /**
     * @param PrimaryWorkerRegistry<TPayload, TResult> $workers
     * @param non-negative-int $workerIndex
     */
    private function processWorker(PrimaryWorkerRegistry $workers, int $workerIndex): void
    {
        while (true) {
            try {
                $this->failure->cancellation()->throwIfRequested();
                $decision = $this->queue->acquire();
                if ($decision instanceof Exhausted) {
                    return;
                }
                if ($decision instanceof Blocked) {
                    $decision->await($this->failure->cancellation());

                    continue;
                }
                if (!$decision instanceof Granted) {
                    throw new LogicException(\sprintf(
                        'Admission queue returned unsupported decision %s.',
                        \get_debug_type($decision),
                    ));
                }

                $this->processLease($workers, $workerIndex, $decision->lease);
            } catch (CancelledException) {
                if ($this->failure->first() === null) {
                    $this->cancelled = true;
                }

                return;
            } catch (Throwable $failure) {
                $this->diagnostics[] = FailureDiagnostic::from($failure);
                $this->failure->record($failure);

                return;
            }
        }
    }

    /**
     * @param PrimaryWorkerRegistry<TPayload, TResult> $workers
     * @param non-negative-int $workerIndex
     * @param TaskLease<TPayload> $lease
     */
    private function processLease(
        PrimaryWorkerRegistry $workers,
        int $workerIndex,
        TaskLease $lease,
    ): void {
        $work = $lease->work;

        $taskId = TaskIdentity::create('work');
        $this->hooks->beforeTask(new TaskBoundary(
            $this->runId,
            $taskId,
            $work,
            $lease->position,
        ));

        $workerAttempt = 1;
        $workerDiagnostics = [];
        while (true) {
            $context = new EventContext(
                runId: $this->runId,
                workId: $work->id,
                taskId: $taskId,
                parentTaskId: null,
                role: ProcessRole::Primary,
                depth: 1,
                sourceId: $taskId . '-worker-attempt-' . $workerAttempt,
            );

            try {
                $request = new ExecuteWork($work, $context, $this->maxPayloadBytes);
                IpcPayloadGuard::assertWithin($request, $this->maxPayloadBytes);
                $execution = $workers->worker($workerIndex)->submit(
                    $request,
                    $this->failure->cancellation(),
                );
                $result = $this->awaitExecution($execution);
                $outcome = self::withDiagnostics($result->outcome, $workerDiagnostics);
                $abortRun = $result->abortRun;

                break;
            } catch (IpcPayloadExceeded $failure) {
                $outcome = TaskOutcome::failed(FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::task($context, ExecutorType::Primary, $workerAttempt),
                ), $workerDiagnostics);
                $abortRun = true;

                break;
            } catch (CancelledException $failure) {
                $outcome = TaskOutcome::cancelled(FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::task($context, ExecutorType::Primary, $workerAttempt),
                ), $workerDiagnostics);
                $abortRun = true;
                if ($this->failure->first() === null) {
                    $this->cancelled = true;
                }

                break;
            } catch (Throwable $failure) {
                $diagnostic = FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::unavailableTask($context, ExecutorType::Primary, $workerAttempt),
                    FailureKind::WorkerCrash,
                );
                try {
                    $action = $this->workerCrashPolicy->onCrash(
                        $work,
                        $diagnostic,
                        $workerAttempt,
                    );
                    $retry = $action === WorkerCrashAction::RetryOnFreshWorker
                        && $workerAttempt < $this->workerCrashPolicy->maxAttempts();
                    if ($retry && !$this->isIdempotent($work)) {
                        throw new LogicException(\sprintf(
                            'Worker crash retry for "%s" requires an explicit idempotency declaration.',
                            $work->id->value,
                        ));
                    }

                    if (!$retry) {
                        $outcome = TaskOutcome::failed($diagnostic, $workerDiagnostics);
                        $abortRun = true;

                        break;
                    }

                    ++$workerAttempt;
                    $workers->replace(
                        $workerIndex,
                        $this->failure->cancellation(),
                    );
                    $workerDiagnostics[] = $diagnostic;
                } catch (Throwable $recoveryFailure) {
                    $outcome = TaskOutcome::failed(
                        FailureDiagnostic::from($recoveryFailure),
                        [...$workerDiagnostics, $diagnostic],
                    );
                    $abortRun = true;

                    break;
                }
            }
        }

        $this->queue->complete($lease, $outcome);
        $this->results[$lease->position] = new WorkResult($work->id, $outcome);
        foreach ($outcome->diagnostics as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
        if ($outcome->failure !== null) {
            $this->diagnostics[] = $outcome->failure;
            if (!$this->cancelled) {
                $this->failed = true;
            }
        }
        $this->hooks->afterTask(new TaskBoundary(
            $this->runId,
            $taskId,
            $work,
            $lease->position,
            $outcome,
        ));

        if ($abortRun && $outcome->failure !== null) {
            $this->failure->record(new RemoteTaskFailure($outcome->failure));
        }
    }

    /** @param WorkEnvelope<TPayload> $work */
    private function isIdempotent(WorkEnvelope $work): bool
    {
        return $this->strategy instanceof DeclaresIdempotentWork
            && $this->strategy->isIdempotent($work);
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
            TaskStatus::Succeeded => TaskOutcome::succeeded(
                $outcome->value,
                $diagnostics,
            ),
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

    /** @param Execution<PrimaryTaskResult, never, EventEnvelope> $execution */
    private function awaitExecution(Execution $execution): PrimaryTaskResult
    {
        $channel = $execution->getChannel();
        $drain = async(function () use ($channel): void {
            try {
                while (!$channel->isClosed()) {
                    $message = $channel->receive($this->failure->cancellation());
                    $this->hooks->envelope($message);
                }
            } catch (ChannelException) {
                // The queue is completed only after the primary result arrives.
            }
        });

        try {
            $result = $execution->await($this->failure->cancellation());
            $drain->await($this->failure->cancellation());

            return $result;
        } catch (Throwable $failure) {
            $channel->close();
            try {
                $drain->await();
            } catch (Throwable) {
                // The task/cancellation failure remains the primary diagnostic.
            }

            throw $failure;
        }
    }
}
