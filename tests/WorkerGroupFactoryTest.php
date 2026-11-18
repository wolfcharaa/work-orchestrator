<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Internal\WorkerGroupFactory;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class WorkerGroupFactoryTest
{
    public function testItCreatesTheRequestedRunningWorkerGroup(): void
    {
        $workers = WorkerGroupFactory::create(2, null);

        try {
            Assert::count($workers, 2);

            foreach ($workers as $worker) {
                Assert::true($worker->isRunning());
            }
        } finally {
            $cleanupFailure = null;

            foreach ($workers as $worker) {
                try {
                    $worker->kill();
                } catch (Throwable $exception) {
                    $cleanupFailure ??= $exception;
                }
            }

            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }
}
