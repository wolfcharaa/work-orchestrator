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
use Fedor\WorkOrchestrator\Internal\Native\NativeProcessPool;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\RunResult;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\RunStatus;
use Throwable;

/** Owns one complete root-directed native process run. @internal */
final class NativeWorkRunner
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
        ?string $autoloadPath,
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

        $results = [];
        $diagnostics = [];
        $cancelled = false;
        $failed = false;

        try {
            try {
                $pool = new NativeProcessPool(
                    runId: $runId,
                    queue: $queue,
                    strategy: $strategy,
                    workerLimit: \min($workerLimit, $queue->count()),
                    configuration: $configuration,
                    hooks: $dispatcher,
                    workerCrashPolicy: $workerCrashPolicy,
                    cancellation: $cancellation,
                    autoloadPath: AutoloaderLocator::resolve($autoloadPath),
                );
                $processing = $pool->run();
            } catch (CancelledException $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new WorkerBootstrapFailed($failure);
            }

            $results = $processing->results;
            $diagnostics = $processing->diagnostics;
            $cancelled = $processing->cancelled;
            $failed = $processing->failed;
        } catch (CancelledException $failure) {
            $cancelled = true;
            $diagnostics[] = FailureDiagnostic::from($failure);
        } catch (WorkerBootstrapFailed $failure) {
            $diagnostics[] = FailureDiagnostic::from($failure);
            $failed = true;
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

    /** @param list<WorkResult<mixed>> $results */
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
