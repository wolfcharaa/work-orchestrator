<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\WorkerBootstrapFailed;
use Fedor\WorkOrchestrator\Hook\RunContext;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\Hook\WorkerStarted;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\RunResult;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\RunStatus;
use Throwable;

/** Owns one complete root run after preflight has succeeded. @internal */
final class ParallelWorkRunner
{
    /**
     * @template TPayload
     * @template TResult
     * @param WorkQueue<TPayload> $queue
     * @param Strategy<TPayload, TResult> $strategy
     * @param WorkerCrashPolicy<TPayload> $workerCrashPolicy
     * @param RootHooks<TPayload> $hooks
     * @return RunResult<TResult>
     */
    public static function run(
        WorkQueue $queue,
        Strategy $strategy,
        int $workerLimit,
        WorkerRuntimeConfiguration $configuration,
        RootHooks $hooks,
        WorkerCrashPolicy $workerCrashPolicy,
        ?Cancellation $cancellation,
    ): RunResult {
        $runId = TaskIdentity::create('run');
        $startedAt = \microtime(true);
        $dispatcher = new HookDispatcher($hooks);
        $dispatcher->beforeRun(new RunContext(
            $runId,
            $queue->count(),
            $workerLimit,
            $startedAt,
        ));

        $registry = null;
        $unownedWorkers = [];
        $results = [];
        $diagnostics = [];
        $cancelled = false;
        $failed = false;

        try {
            $workerCount = \min($workerLimit, $queue->count());
            try {
                $unownedWorkers = WorkerGroupFactory::create($workerCount, $cancellation);
                WorkerRuntimeInitializer::initialize(
                    $unownedWorkers,
                    $strategy,
                    $configuration,
                    $cancellation,
                );
            } catch (CancelledException $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new WorkerBootstrapFailed($failure);
            }
            foreach ($unownedWorkers as $index => $_worker) {
                $dispatcher->worker(new WorkerStarted('primary-' . $index));
            }
            $registry = new PrimaryWorkerRegistry(
                $unownedWorkers,
                $strategy,
                $configuration,
                $dispatcher,
            );
            $unownedWorkers = [];

            $processing = (new WorkProcessingRun(
                runId: $runId,
                queue: $queue,
                maxPayloadBytes: $configuration->maxIpcPayloadBytes,
                hooks: $dispatcher,
                strategy: $strategy,
                workerCrashPolicy: $workerCrashPolicy,
                cancellation: $cancellation,
            ))->run($registry);
            $results = $processing->results;
            $diagnostics = $processing->diagnostics;
            $cancelled = $processing->cancelled;
            $failed = $processing->failed;
        } catch (CancelledException $failure) {
            $cancelled = true;
            $diagnostics[] = FailureDiagnostic::from($failure);
        } catch (Throwable $failure) {
            $diagnostics[] = FailureDiagnostic::from($failure);
            $failed = true;
        } finally {
            if ($unownedWorkers !== []) {
                $cleanupFailures = WorkerGroupCleanup::run(
                    $unownedWorkers,
                    $configuration->cancellationGraceSeconds,
                );
                foreach ($cleanupFailures as $failure) {
                    $diagnostics[] = FailureDiagnostic::from($failure, kind: FailureKind::Cleanup);
                }
                if ($cleanupFailures !== []) {
                    $failed = true;
                }
            }
            if ($registry !== null) {
                $cleanupFailures = $registry->cleanup();
                foreach ($cleanupFailures as $failure) {
                    $diagnostics[] = FailureDiagnostic::from($failure, kind: FailureKind::Cleanup);
                }
                if ($cleanupFailures !== []) {
                    $failed = true;
                }
            }
        }

        $status = self::status($results, $cancelled, $failed);
        $summary = new RunSummary(
            runId: $runId,
            status: $status,
            results: $results,
            diagnostics: $diagnostics,
            startedAt: $startedAt,
            finishedAt: \microtime(true),
        );
        $dispatcher->afterRun($summary);
        $dispatcher->awaitAll();

        return new RunResult(
            runId: $runId,
            status: $status,
            results: $results,
            diagnostics: $diagnostics,
            observerFailures: $dispatcher->failures(),
            summary: $summary,
        );
    }

    /**
     * @param list<WorkResult<mixed>> $results
     */
    private static function status(array $results, bool $cancelled, bool $failed): RunStatus
    {
        if ($cancelled) {
            return RunStatus::Cancelled;
        }

        if ($failed) {
            return RunStatus::Failed;
        }

        foreach ($results as $result) {
            if (!$result->outcome->isSuccessful()) {
                return RunStatus::Failed;
            }
        }

        return RunStatus::Succeeded;
    }

    private function __construct() {}
}
