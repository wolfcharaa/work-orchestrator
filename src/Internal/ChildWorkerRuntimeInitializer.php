<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;

use function Amp\Future\awaitAll;

use Amp\Parallel\Worker\Worker;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Internal\Task\InitializeChildWorkerRuntime;

/** @internal */
final class ChildWorkerRuntimeInitializer
{
    /** @param non-empty-list<Worker> $workers */
    public static function initialize(
        array $workers,
        ?ManagedTaskBootstrap $bootstrap,
        Cancellation $cancellation,
    ): void {
        /** @var array<int, Future<true>> $futures */
        $futures = [];
        foreach ($workers as $index => $worker) {
            $futures[$index] = async(static fn(): true => $worker
                ->submit(new InitializeChildWorkerRuntime($bootstrap), $cancellation)
                ->await($cancellation));
        }

        [$errors] = awaitAll($futures);
        if ($errors !== []) {
            throw $errors[\array_key_first($errors)];
        }
    }

    private function __construct() {}
}
