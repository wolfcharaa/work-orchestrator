<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Fedor\WorkOrchestrator\AbortRun;
use Fedor\WorkOrchestrator\CompleteAs;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\Contract\HandlesTaskFailure;
use Fedor\WorkOrchestrator\Contract\ProvidesContainer;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Contract\ProvidesTaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\RoutesChildApplicationEvents;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\RetryScheduled;
use Fedor\WorkOrchestrator\Event\TaskFailed;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\Exception\WorkerCleanupFailed;
use Fedor\WorkOrchestrator\FailCurrent;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\RetryCurrent;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Throwable;

/** Process-local Strategy and worker-lifetime resources. @internal */
final class WorkerRuntime
{
    /** @var Strategy<mixed, mixed>|null */
    private static ?Strategy $strategy = null;

    private static ?WorkerRuntimeConfiguration $configuration = null;

    private static ?ConcurrentManagedTaskExecutor $concurrent = null;

    private static ?ManagedChildWorkerPool $parallel = null;

    /**
     * @template TPayload
     * @template TResult
     * @param Strategy<TPayload, TResult> $strategy
     */
    public static function initialize(
        Strategy $strategy,
        WorkerRuntimeConfiguration $configuration,
        Cancellation $cancellation,
    ): void {
        if (self::$strategy !== null) {
            throw new LogicException('Worker runtime is already initialized.');
        }

        $bootAttempted = false;
        try {
            if ($strategy instanceof BootstrapsApplication) {
                $bootAttempted = true;
                $strategy->boot($cancellation);
            }

            if ($strategy instanceof HandlesTaskFailure && $strategy->maxAttempts() < 1) {
                throw new LogicException('Strategy retry attempt limit must be positive.');
            }

            if ($strategy instanceof UsesManagedTasks) {
                $container = $strategy instanceof ProvidesContainer
                    ? $strategy->container()
                    : null;
                $crashPolicy = $strategy instanceof ProvidesManagedTaskCrashPolicy
                    ? $strategy->managedTaskCrashPolicy()
                    : new AbortManagedTaskCrashPolicy();
                if ($crashPolicy->maxAttempts() < 1) {
                    throw new LogicException('Managed task crash attempt limit must be positive.');
                }
                self::$concurrent = new ConcurrentManagedTaskExecutor(
                    $configuration->concurrentTasks,
                    $container,
                    $configuration->maxIpcPayloadBytes,
                );
                self::$parallel = new ManagedChildWorkerPool(
                    workerLimit: $configuration->childWorkers,
                    bootstrap: $strategy instanceof ProvidesManagedTaskBootstrap
                        ? $strategy->managedTaskBootstrap()
                        : null,
                    eventRouter: $strategy instanceof RoutesChildApplicationEvents
                        ? $strategy
                        : null,
                    crashPolicy: $crashPolicy,
                    maxPayloadBytes: $configuration->maxIpcPayloadBytes,
                    cancellationGraceSeconds: $configuration->cancellationGraceSeconds,
                );
            }

            $cancellation->throwIfRequested();
        } catch (Throwable $primary) {
            self::$concurrent = null;
            self::$parallel = null;

            if ($bootAttempted) {
                try {
                    $strategy->close();
                } catch (Throwable $cleanup) {
                    throw new WorkerCleanupFailed($primary, [$cleanup]);
                }
            }

            throw $primary;
        }

        self::$strategy = $strategy;
        self::$configuration = $configuration;
    }

    /** @param WorkEnvelope<mixed> $work */
    public static function execute(
        WorkEnvelope $work,
        EventContext $context,
        Cancellation $cancellation,
    ): PrimaryTaskResult {
        $strategy = self::$strategy
            ?? throw new LogicException('Worker runtime is not initialized.');
        $configuration = self::$configuration
            ?? throw new LogicException('Worker runtime configuration is not initialized.');
        $maxAttempts = $strategy instanceof HandlesTaskFailure
            ? $strategy->maxAttempts()
            : 1;
        $diagnostics = [];

        $cancellationId = $cancellation->subscribe(
            static function (CancelledException $_failure): void {
                self::$parallel?->forceClose();
            },
        );

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
                ProcessEvents::emitCore(new TaskStarted(ExecutorType::Primary, $attempt));

                try {
                    $result = self::executeAttempt($strategy, $work, $context, $cancellation);
                    IpcPayloadGuard::assertWithin($result->value, $configuration->maxIpcPayloadBytes);
                    ProcessEvents::emitCore(new TaskSucceeded(ExecutorType::Primary, $attempt));

                    return new PrimaryTaskResult(TaskOutcome::succeeded(
                        $result->value,
                        [...$diagnostics, ...$result->diagnostics],
                    ), false);
                } catch (CancelledException $failure) {
                    self::emitFailure($failure, $attempt);

                    return new PrimaryTaskResult(
                        TaskOutcome::cancelled(self::diagnostic($failure, $context, $attempt), $diagnostics),
                        true,
                    );
                } catch (Throwable $failure) {
                    self::emitFailure($failure, $attempt);
                    if (!$strategy instanceof HandlesTaskFailure) {
                        return new PrimaryTaskResult(
                            TaskOutcome::failed(self::diagnostic($failure, $context, $attempt), $diagnostics),
                            true,
                        );
                    }

                    $diagnostic = self::diagnostic($failure, $context, $attempt);
                    try {
                        $action = $strategy->onFailure($work, $failure, $attempt, $cancellation);
                    } catch (Throwable $policyFailure) {
                        return new PrimaryTaskResult(
                            TaskOutcome::failed(
                                self::diagnostic($policyFailure, $context, $attempt),
                                [...$diagnostics, $diagnostic],
                            ),
                            true,
                        );
                    }
                    if ($action instanceof CompleteAs) {
                        $diagnostics[] = $diagnostic;
                        IpcPayloadGuard::assertWithin($action->value, $configuration->maxIpcPayloadBytes);
                        ProcessEvents::emitCore(new TaskSucceeded(ExecutorType::Primary, $attempt));

                        return new PrimaryTaskResult(
                            TaskOutcome::succeeded($action->value, $diagnostics),
                            false,
                        );
                    }
                    if ($action instanceof RetryCurrent && $attempt < $maxAttempts) {
                        $diagnostics[] = $diagnostic;
                        ProcessEvents::emitCore(new RetryScheduled(
                            ExecutorType::Primary,
                            $attempt + 1,
                            $action->delaySeconds,
                        ));
                        if ($action->delaySeconds > 0) {
                            delay($action->delaySeconds, cancellation: $cancellation);
                        }

                        continue;
                    }

                    $abort = $action instanceof AbortRun;
                    if (!$abort && !$action instanceof FailCurrent && !$action instanceof RetryCurrent) {
                        throw new LogicException(\sprintf(
                            'Unsupported failure action %s.',
                            $action::class,
                        ));
                    }

                    return new PrimaryTaskResult(
                        TaskOutcome::failed(self::diagnostic($failure, $context, $attempt), $diagnostics),
                        $abort,
                    );
                }
            }

            throw new LogicException('Primary task attempt loop ended without an outcome.');
        } finally {
            $cancellation->unsubscribe($cancellationId);
        }
    }

    public static function finalize(): void
    {
        $strategy = self::$strategy;
        $parallel = self::$parallel;
        self::$strategy = null;
        self::$configuration = null;
        self::$concurrent = null;
        self::$parallel = null;

        $failures = [];
        try {
            $parallel?->close();
        } catch (Throwable $failure) {
            $failures[] = $failure;
        }

        if ($strategy instanceof BootstrapsApplication) {
            try {
                $strategy->close();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        if (\count($failures) === 1) {
            throw $failures[0];
        }
        if ($failures !== []) {
            throw new WorkerCleanupFailed(null, $failures);
        }
    }

    /**
     * @param Strategy<mixed, mixed> $strategy
     * @param WorkEnvelope<mixed> $work
     */
    private static function executeAttempt(
        Strategy $strategy,
        WorkEnvelope $work,
        EventContext $context,
        Cancellation $cancellation,
    ): AttemptResult {
        if (!$strategy instanceof UsesManagedTasks) {
            return new AttemptResult($strategy->run($work, $cancellation), []);
        }

        $concurrent = self::$concurrent
            ?? throw new LogicException('Concurrent task executor is not initialized.');
        $parallel = self::$parallel
            ?? throw new LogicException('Parallel task executor is not initialized.');
        $configuration = self::$configuration
            ?? throw new LogicException('Worker runtime configuration is not initialized.');
        $policy = $strategy instanceof ProvidesTaskExecutionPolicy
            ? $strategy->taskExecutionPolicy()
            : new DefaultTaskExecutionPolicy();
        $group = new DefaultManagedTaskGroup(
            $context,
            $cancellation,
            $policy,
            $concurrent,
            $parallel,
            $configuration->maxIpcPayloadBytes,
        );
        $strategy->useManagedTasks($group);
        $primaryFailure = null;
        $value = null;

        try {
            $value = $strategy->run($work, $cancellation);
        } catch (Throwable $failure) {
            $primaryFailure = $failure;
            $group->cancel($failure);
        }

        try {
            $group->join();
        } catch (Throwable $groupFailure) {
            if ($primaryFailure !== null) {
                throw new WorkerCleanupFailed($primaryFailure, [$groupFailure]);
            }

            throw $groupFailure;
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }

        return new AttemptResult($value, $group->diagnostics());
    }

    private static function emitFailure(Throwable $failure, int $attempt): void
    {
        ProcessEvents::emitCore(new TaskFailed(
            executor: ExecutorType::Primary,
            attempt: $attempt,
            failureType: $failure::class,
            message: $failure->getMessage(),
        ));
    }

    private static function diagnostic(
        Throwable $failure,
        EventContext $context,
        int $attempt,
    ): FailureDiagnostic {
        return FailureDiagnostic::from(
            $failure,
            FailureOrigin::task($context, ExecutorType::Primary, $attempt),
        );
    }

    private function __construct() {}
}
