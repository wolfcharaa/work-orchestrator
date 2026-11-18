<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Internal\WorkerGroupCleanup;
use Fedor\WorkOrchestrator\Internal\WorkerGroupFactory;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeInitializer;
use Fedor\WorkOrchestrator\Tests\Fixture\LifecycleStrategy;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class WorkerGroupCleanupTest
{
    public function testItFinalizesStrategiesAndStopsEveryWorker(): void
    {
        $lifecycle = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-cleanup-');
        if ($lifecycle === false) {
            throw new LogicException('Failed to prepare a cleanup lifecycle fixture.');
        }

        $workers = WorkerGroupFactory::create(1, null);

        try {
            WorkerRuntimeInitializer::initialize(
                $workers,
                new LifecycleStrategy($lifecycle, factor: 2),
                new WorkerRuntimeConfiguration(1, 1, 1_048_576),
                null,
            );

            Assert::same(WorkerGroupCleanup::run($workers), []);
            Assert::false($workers[0]->isRunning());

            $lines = \file($lifecycle, \FILE_IGNORE_NEW_LINES);
            if (!\is_array($lines) || \count($lines) !== 2) {
                throw new LogicException('Expected one bootstrap and one finalization record.');
            }

            [$boot, $close] = $lines;
            Assert::true(\str_starts_with($boot, 'boot:'));
            Assert::true(\str_starts_with($close, 'close:'));
            Assert::same(
                \substr($boot, \strlen('boot:')),
                \substr($close, \strlen('close:')),
            );
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->kill();
                }
            }

            \unlink($lifecycle);
        }
    }

    public function testItCanFinalizeEveryWorkerWhenNoStrategyWasInitialized(): void
    {
        $workers = WorkerGroupFactory::create(1, null);

        Assert::same(WorkerGroupCleanup::run($workers), []);
        Assert::false($workers[0]->isRunning());
    }
}
