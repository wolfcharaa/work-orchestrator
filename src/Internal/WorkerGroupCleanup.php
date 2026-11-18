<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Future;

use function Amp\Future\awaitAll;

use Amp\Parallel\Worker\Worker;
use Amp\TimeoutCancellation;
use Fedor\WorkOrchestrator\Internal\Task\FinalizeWorkerRuntime;
use Throwable;

/** @internal */
final class WorkerGroupCleanup
{
    /**
     * @param non-empty-list<Worker> $workers
     *
     * @return list<Throwable>
     */
    public static function run(array $workers, float $graceSeconds = 5.0): array
    {
        $failures = [];
        $failedWorkers = [];

        /** @var array<int, Future<null>> $finalizers */
        $finalizers = [];
        foreach ($workers as $index => $worker) {
            if (!$worker->isRunning()) {
                continue;
            }

            $finalizers[$index] = async(static function () use ($worker, $graceSeconds): null {
                $cancellation = new TimeoutCancellation($graceSeconds);

                return $worker
                    ->submit(new FinalizeWorkerRuntime(), $cancellation)
                    ->await($cancellation);
            });
        }

        [$finalizeErrors] = awaitAll($finalizers);
        foreach ($finalizeErrors as $index => $exception) {
            $failures[] = $exception;
            $failedWorkers[$index] = true;
        }

        /** @var array<int, Future<void>> $shutdowns */
        $shutdowns = [];
        foreach ($workers as $index => $worker) {
            if (isset($failedWorkers[$index])) {
                try {
                    $worker->kill();
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }

                continue;
            }

            if ($worker->isRunning()) {
                $shutdowns[$index] = async($worker->shutdown(...));
            }
        }

        [$shutdownErrors] = awaitAll($shutdowns);
        foreach ($shutdownErrors as $index => $exception) {
            $failures[] = $exception;

            try {
                $worker = $workers[$index]
                    ?? throw new \LogicException('Failed worker index is not available.');
                $worker->kill();
            } catch (Throwable $killFailure) {
                $failures[] = $killFailure;
            }
        }

        return $failures;
    }
}
