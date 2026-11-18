<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;

use function Amp\Future\awaitAll;

use Amp\Parallel\Worker\Worker;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Internal\Task\InitializeWorkerRuntime;

/**
 * Initializes process-scoped Strategy capabilities in every primary worker.
 *
 * @internal
 */
final class WorkerRuntimeInitializer
{
    /**
     * @template TPayload
     * @template TResult
     * @param non-empty-list<Worker> $workers
     * @param Strategy<TPayload, TResult> $strategy
     */
    public static function initialize(
        array $workers,
        Strategy $strategy,
        WorkerRuntimeConfiguration $configuration,
        ?Cancellation $cancellation,
    ): void {
        /** @var array<int, Future<true>> $futures */
        $futures = [];

        foreach ($workers as $index => $worker) {
            $futures[$index] = async(static fn(): true => $worker
                ->submit(new InitializeWorkerRuntime($strategy, $configuration), $cancellation)
                ->await($cancellation));
        }

        [$errors] = awaitAll($futures);
        if ($errors !== []) {
            throw $errors[\array_key_first($errors)];
        }
    }
}
