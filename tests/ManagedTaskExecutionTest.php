<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Tests\Fixture\AbortChildCrashStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\ConcurrentTasksStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\ContainerTasksStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\CrashChildStrategy;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ManagedTaskExecutionTest
{
    public function testConcurrentPolicyUsesBoundedFibersInsideThePrimaryProcess(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-concurrent-');
        if ($path === false) {
            throw new LogicException('Failed to create a concurrent task input.');
        }

        try {
            $startedAt = \microtime(true);
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->concurrentTasksPerPrimary(2)
                ->run(new ConcurrentTasksStrategy());
            $duration = \microtime(true) - $startedAt;
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Succeeded);
        $value = $result->value(new WorkId($path));
        if (!isset($value['primary'], $value['children'])
            || !\is_int($value['primary'])
            || !\is_array($value['children'])) {
            throw new LogicException('Concurrent Strategy returned an invalid result.');
        }

        Assert::same(\array_column($value['children'], 'process'), [
            $value['primary'],
            $value['primary'],
        ]);
        Assert::true($duration < 0.2);
    }

    public function testHandlersResolveFromTheExecutorLocalContainerForBothModes(): void
    {
        $input = self::temporaryFile();
        $concurrentLifecycle = self::temporaryFile();
        $parallelLifecycle = self::temporaryFile();

        try {
            $concurrent = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->run(new ContainerTasksStrategy(
                    ExecutionMode::Concurrent,
                    4,
                    $concurrentLifecycle,
                ));
            $parallel = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->childWorkersPerPrimary(1)
                ->run(new ContainerTasksStrategy(
                    ExecutionMode::Parallel,
                    4,
                    $parallelLifecycle,
                ));

            Assert::same($concurrent->value(new WorkId($input)), [12, 20]);
            Assert::same($parallel->value(new WorkId($input)), [12, 20]);
            Assert::same(\file($concurrentLifecycle, \FILE_IGNORE_NEW_LINES), []);
            $parallelLines = \file($parallelLifecycle, \FILE_IGNORE_NEW_LINES);
            if (!\is_array($parallelLines) || \count($parallelLines) !== 2) {
                throw new LogicException('Expected one child bootstrap and close record.');
            }
            [$boot, $close] = $parallelLines;
            Assert::true(\str_starts_with($boot, 'boot:'));
            Assert::true(\str_starts_with($close, 'close:'));
            Assert::same(
                \substr($boot, \strlen('boot:')),
                \substr($close, \strlen('close:')),
            );
        } finally {
            \unlink($input);
            \unlink($concurrentLifecycle);
            \unlink($parallelLifecycle);
        }
    }

    public function testAnIdempotentManagedTaskCanRecoverOnAFreshChildWorker(): void
    {
        $input = self::temporaryFile();
        $marker = $input . '.child-crashed';

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->childWorkersPerPrimary(1)
                ->run(new CrashChildStrategy($marker));
        } finally {
            \unlink($input);
            if (\is_file($marker)) {
                \unlink($marker);
            }
        }

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($result->value(new WorkId($input)), 42);
        Assert::count($result->diagnostics(), 1);
    }

    public function testAnUnrecoverableManagedChildCrashRemainsTheTerminalCause(): void
    {
        $input = self::temporaryFile();
        $marker = $input . '.child-crashed';

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->childWorkersPerPrimary(1)
                ->run(new AbortChildCrashStrategy($marker));
        } finally {
            \unlink($input);
            if (\is_file($marker)) {
                \unlink($marker);
            }
        }

        $work = $result->result(new WorkId($input));
        Assert::same($result->status, RunStatus::Failed);
        Assert::true($work !== null);
        $failure = $work->outcome->failure
            ?? throw new LogicException('Expected a terminal managed child crash diagnostic.');
        Assert::same($failure->kind, FailureKind::WorkerCrash);
        Assert::same($failure->origin->executor, ExecutorType::Parallel);
        Assert::count($work->outcome->diagnostics, 0);
        $diagnostics = $result->diagnostics();
        Assert::count($diagnostics, 2);
        $crash = $diagnostics[0]
            ?? throw new LogicException('Expected the managed child crash diagnostic.');
        $cleanup = $diagnostics[1]
            ?? throw new LogicException('Expected the managed child cleanup diagnostic.');
        Assert::same($crash->kind, FailureKind::WorkerCrash);
        Assert::same($cleanup->kind, FailureKind::Cleanup);
    }

    private static function temporaryFile(): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-managed-');
        if ($path === false) {
            throw new LogicException('Failed to create a managed task fixture.');
        }

        return $path;
    }
}
