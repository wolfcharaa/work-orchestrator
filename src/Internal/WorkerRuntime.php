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
use Fedor\WorkOrchestrator\Contract\ProvidesTaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\RetryScheduled;
use Fedor\WorkOrchestrator\Event\TaskFailed;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\Exception\ManagedTaskFailed;
use Fedor\WorkOrchestrator\Exception\ManagedTaskGroupFailed;
use Fedor\WorkOrchestrator\Exception\WorkerCleanupFailed;
use Fedor\WorkOrchestrator\FailCurrent;
use Fedor\WorkOrchestrator\Internal\Native\PrimaryProtocolClient;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\RetryCurrent;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Throwable;

/** Process-local Strategy and worker-lifetime resources. @internal */
final class WorkerRuntime
{
    /** @var Strategy<mixed, mixed>|null */
    private static ?Strategy $strategy = null;

    private static ?WorkerRuntimeConfiguration $configuration = null;

    private static ?PrimaryProtocolClient $protocol = null;

    private static ?ConcurrentManagedTaskExecutor $concurrent = null;

    /**
     * @template TPayload
     * @template TResult
     * @param Strategy<TPayload, TResult> $strategy
     */
    public static function initialize(
        Strategy $strategy,
        WorkerRuntimeConfiguration $configuration,
        ?PrimaryProtocolClient $protocol,
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
                self::$concurrent = new ConcurrentManagedTaskExecutor(
                    $configuration->concurrentTasks,
                    $strategy instanceof ProvidesContainer ? $strategy->container() : null,
                    $configuration->maxIpcPayloadBytes,
                );
            }

            $cancellation->throwIfRequested();
        } catch (Throwable $primary) {
            self::$concurrent = null;
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
        self::$protocol = $protocol;
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
                $evidence = self::failureEvidence($failure, $context, $attempt);
                if (!$strategy instanceof HandlesTaskFailure) {
                    return new PrimaryTaskResult(
                        TaskOutcome::failed(
                            $evidence->terminal,
                            [...$diagnostics, ...$evidence->history()],
                        ),
                        true,
                    );
                }

                try {
                    $action = $strategy->onFailure($work, $failure, $attempt, $cancellation);
                } catch (Throwable $policyFailure) {
                    $policyEvidence = self::failureEvidence(
                        $policyFailure,
                        $context,
                        $attempt,
                    );

                    return new PrimaryTaskResult(
                        TaskOutcome::failed(
                            $policyEvidence->terminal,
                            [
                                ...$diagnostics,
                                ...$evidence->recoveredHistory(),
                                ...$policyEvidence->history(),
                            ],
                        ),
                        true,
                    );
                }
                if ($action instanceof CompleteAs) {
                    $diagnostics = [...$diagnostics, ...$evidence->recoveredHistory()];
                    IpcPayloadGuard::assertWithin($action->value, $configuration->maxIpcPayloadBytes);
                    ProcessEvents::emitCore(new TaskSucceeded(ExecutorType::Primary, $attempt));

                    return new PrimaryTaskResult(
                        TaskOutcome::succeeded($action->value, $diagnostics),
                        false,
                    );
                }
                if ($action instanceof RetryCurrent && $attempt < $maxAttempts) {
                    $diagnostics = [...$diagnostics, ...$evidence->recoveredHistory()];
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
                    TaskOutcome::failed(
                        $evidence->terminal,
                        [...$diagnostics, ...$evidence->history()],
                    ),
                    $abort,
                );
            }
        }

        throw new LogicException('Primary task attempt loop ended without an outcome.');
    }

    public static function finalize(): void
    {
        $strategy = self::$strategy;
        self::$strategy = null;
        self::$configuration = null;
        self::$protocol = null;
        self::$concurrent = null;

        $failures = [];
        if ($strategy instanceof BootstrapsApplication) {
            try {
                $strategy->close();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        if ($failures !== []) {
            throw $failures[0];
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

        $protocol = self::$protocol
            ?? throw new LogicException('Primary worker protocol is not initialized.');
        $concurrent = self::$concurrent
            ?? throw new LogicException('Concurrent task executor is not initialized.');
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
            $protocol,
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

    private static function failureEvidence(
        Throwable $failure,
        EventContext $context,
        int $attempt,
    ): FailureEvidence {
        if ($failure instanceof ManagedTaskFailed) {
            return self::distinctEvidence(new FailureEvidence(
                $failure->diagnostic,
                $failure->diagnostics,
            ));
        }

        if ($failure instanceof ManagedTaskGroupFailed) {
            return self::distinctEvidence(new FailureEvidence(
                $failure->failures[0],
                $failure->diagnostics,
                \array_slice($failure->failures, 1),
            ));
        }

        if ($failure instanceof WorkerCleanupFailed) {
            if ($failure->primaryFailure !== null) {
                $primary = self::failureEvidence($failure->primaryFailure, $context, $attempt);
                $after = $primary->after;
                foreach ($failure->cleanupFailures as $cleanupFailure) {
                    $after = [
                        ...$after,
                        ...self::cleanupDiagnostics($cleanupFailure, $context, $attempt),
                    ];
                }

                return self::distinctEvidence(new FailureEvidence(
                    $primary->terminal,
                    $primary->before,
                    $after,
                ));
            }

            $after = [];
            foreach ($failure->cleanupFailures as $cleanupFailure) {
                $after = [
                    ...$after,
                    ...self::cleanupDiagnostics($cleanupFailure, $context, $attempt),
                ];
            }

            return self::distinctEvidence(new FailureEvidence(
                self::diagnostic($failure, $context, $attempt),
                after: $after,
            ));
        }

        return new FailureEvidence(self::diagnostic($failure, $context, $attempt));
    }

    /** @return list<FailureDiagnostic> */
    private static function cleanupDiagnostics(
        Throwable $failure,
        EventContext $context,
        int $attempt,
    ): array {
        if ($failure instanceof ManagedTaskFailed || $failure instanceof ManagedTaskGroupFailed) {
            return self::failureEvidence($failure, $context, $attempt)->recoveredHistory();
        }

        if ($failure instanceof WorkerCleanupFailed) {
            return self::failureEvidence($failure, $context, $attempt)->recoveredHistory();
        }

        return [FailureDiagnostic::from(
            $failure,
            FailureOrigin::task($context, ExecutorType::Primary, $attempt),
            FailureKind::Cleanup,
        )];
    }

    private static function distinctEvidence(FailureEvidence $evidence): FailureEvidence
    {
        $seen = [$evidence->terminal];
        $before = self::distinctDiagnostics($evidence->before, $seen);
        $after = self::distinctDiagnostics($evidence->after, $seen);

        return new FailureEvidence($evidence->terminal, $before, $after);
    }

    /**
     * @param list<FailureDiagnostic> $diagnostics
     * @param list<FailureDiagnostic> $seen
     * @return list<FailureDiagnostic>
     */
    private static function distinctDiagnostics(array $diagnostics, array &$seen): array
    {
        $distinct = [];
        foreach ($diagnostics as $diagnostic) {
            foreach ($seen as $existing) {
                if (self::sameDiagnostic($diagnostic, $existing)) {
                    continue 2;
                }
            }

            $seen[] = $diagnostic;
            $distinct[] = $diagnostic;
        }

        return $distinct;
    }

    private static function sameDiagnostic(
        FailureDiagnostic $first,
        FailureDiagnostic $second,
    ): bool {
        return $first === $second || (
            $first->kind === $second->kind
            && $first->type === $second->type
            && $first->message === $second->message
            && $first->occurredAt === $second->occurredAt
            && $first->origin->role === $second->origin->role
            && $first->origin->executor === $second->origin->executor
            && $first->origin->processId === $second->origin->processId
            && self::sameWorkId($first->origin->workId, $second->origin->workId)
            && $first->origin->taskId === $second->origin->taskId
            && $first->origin->attempt === $second->origin->attempt
        );
    }

    private static function sameWorkId(?WorkId $first, ?WorkId $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equals($second);
    }

    private function __construct() {}
}
