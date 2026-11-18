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
final class WorkerRuntimeInitializerTest
{
    public function testItInitializesEveryWorkerRuntime(): void
    {
        $lifecycle = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-initializer-');
        if ($lifecycle === false) {
            throw new LogicException('Failed to prepare an initializer lifecycle fixture.');
        }

        $workers = WorkerGroupFactory::create(2, null);

        try {
            WorkerRuntimeInitializer::initialize(
                $workers,
                new LifecycleStrategy($lifecycle, factor: 2),
                new WorkerRuntimeConfiguration(1, 1, 1_048_576),
                null,
            );

            $lines = \file($lifecycle, \FILE_IGNORE_NEW_LINES);
            Assert::true(\is_array($lines));
            Assert::count($lines, 2);
        } finally {
            $cleanupFailures = WorkerGroupCleanup::run($workers);
            if ($cleanupFailures !== []) {
                throw $cleanupFailures[0];
            }

            \unlink($lifecycle);
        }
    }
}
