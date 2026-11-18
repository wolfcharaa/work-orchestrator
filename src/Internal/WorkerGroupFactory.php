<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;

use function Amp\Future\awaitAll;

use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\Worker;
use Throwable;

/** @internal */
final class WorkerGroupFactory
{
    /** @return non-empty-list<Worker> */
    public static function create(int $workerCount, ?Cancellation $cancellation): array
    {
        if ($workerCount < 1) {
            throw new \LogicException('Parallel runner requires at least one worker.');
        }

        /** @var array<int, Future<Worker>> $futures */
        $futures = [];
        $workerFactory = new ContextWorkerFactory();

        for ($index = 0; $index < $workerCount; ++$index) {
            $futures[$index] = async(
                static fn(): Worker => $workerFactory->create($cancellation),
            );
        }

        [$errors] = awaitAll($futures);

        if ($errors !== []) {
            foreach ($futures as $future) {
                try {
                    $worker = $future->await();
                    if ($worker instanceof Worker) {
                        $worker->kill();
                    }
                } catch (Throwable) {
                    // The creation failure below remains the primary error.
                }
            }

            throw $errors[\array_key_first($errors)];
        }

        $workers = [];
        foreach ($futures as $future) {
            $worker = $future->await();
            if (!$worker instanceof Worker) {
                throw new \LogicException('Worker factory returned an invalid value.');
            }

            $workers[] = $worker;
        }

        if ($workers === []) {
            throw new \LogicException('Worker factory did not create any workers.');
        }

        return $workers;
    }
}
