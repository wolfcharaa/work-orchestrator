<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Future;
use Amp\Parallel\Worker\Worker;
use Amp\TimeoutCancellation;
use Throwable;

/** Bounds a worker's pending-job wait before cleanup force-kills it. @internal */
final class BoundedWorkerShutdown
{
    /** @return Future<void> */
    public static function start(Worker $worker, float $graceSeconds): Future
    {
        return async(static function () use ($worker, $graceSeconds): void {
            if ($graceSeconds === 0.0) {
                $worker->kill();

                return;
            }

            $shutdown = async($worker->shutdown(...));
            try {
                $shutdown->await(new TimeoutCancellation($graceSeconds));
            } catch (Throwable $failure) {
                $shutdown->ignore();

                throw $failure;
            }
        });
    }

    private function __construct() {}
}
