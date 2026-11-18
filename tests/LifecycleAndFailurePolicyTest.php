<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use function Amp\async;

use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\RetryScheduled;
use Fedor\WorkOrchestrator\Event\TaskFailed;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Hook\ObserverFailure;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Tests\Fixture\ContinueAfterFailureStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\CountBytesStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\CrashOnceStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\FailurePolicyThrowsStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\RetryingStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\RetryWorkerCrashPolicy;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class LifecycleAndFailurePolicyTest
{
    public function testBoundedRetryProducesOneFinalValueAndCompleteLifecycle(): void
    {
        $path = self::temporaryFile('retry');
        $events = [];

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->onTaskLifecycle(static function (EventEnvelope $event) use (&$events): void {
                    $events[] = $event->event::class;
                })
                ->run(new RetryingStrategy());
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($result->value(new WorkId($path)), 2);
        Assert::same($events, [
            TaskStarted::class,
            TaskFailed::class,
            RetryScheduled::class,
            TaskStarted::class,
            TaskSucceeded::class,
        ]);
    }

    public function testFailCurrentKeepsAdmittingFilesButMakesTheRunFailed(): void
    {
        $failed = self::temporaryFile('failed');
        $succeeded = self::temporaryFile('succeeded');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$failed, $succeeded]))
                ->primaryWorkers(1)
                ->run(new ContinueAfterFailureStrategy($failed));
        } finally {
            \unlink($failed);
            \unlink($succeeded);
        }

        Assert::same($result->status, RunStatus::Failed);
        Assert::count($result->results(), 2);
        Assert::same($result->values(), [$succeeded]);
        Assert::count($result->diagnostics(), 1);
    }

    public function testObserverFailuresAreReportedWithoutCancellingUsefulWork(): void
    {
        $path = self::temporaryFile('abc');
        $notifications = [];

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->beforeRun(static function (): void {
                    throw new LogicException('Synchronous observer failure.');
                })
                ->afterTask(static fn() => async(static function (): void {
                    throw new LogicException('Asynchronous observer failure.');
                }))
                ->onObserverFailure(static function (ObserverFailure $failure) use (&$notifications): void {
                    $notifications[] = $failure->phase;
                })
                ->run(new CountBytesStrategy());
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($result->value(new WorkId($path)), ['bytes' => 3]);
        Assert::same($notifications, ['beforeRun', 'afterTask']);
        Assert::count($result->observerFailures(), 2);
    }

    public function testAnExplicitlyIdempotentFileCanRetryOnAFreshPrimaryWorker(): void
    {
        $path = self::temporaryFile('crash');
        $marker = $path . '.worker-crashed';

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->workerCrashPolicy(new RetryWorkerCrashPolicy())
                ->run(new CrashOnceStrategy($marker));
        } finally {
            \unlink($path);
            if (\is_file($marker)) {
                \unlink($marker);
            }
        }

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($result->value(new WorkId($path)), $path);
        Assert::count($result->diagnostics(), 1);
        $work = $result->result(new WorkId($path));
        Assert::true($work !== null);
        Assert::count($work->outcome->diagnostics, 1);
        $crash = $work->outcome->diagnostics[0]
            ?? throw new LogicException('Expected the recovered worker crash diagnostic.');
        Assert::same($crash->kind, FailureKind::WorkerCrash);
    }

    public function testAFailurePolicyErrorRemainsAnApplicationFailureInsideTheWorkLedger(): void
    {
        $path = self::temporaryFile('policy');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->run(new FailurePolicyThrowsStrategy());
        } finally {
            \unlink($path);
        }

        $work = $result->result(new WorkId($path));
        Assert::same($result->status, RunStatus::Failed);
        Assert::true($work !== null);
        $failure = $work->outcome->failure
            ?? throw new LogicException('Expected a terminal failure policy diagnostic.');
        Assert::same($failure->message, 'Failure policy failed.');
        Assert::same($failure->kind, FailureKind::Application);
        Assert::count($work->outcome->diagnostics, 1);
        $workFailure = $work->outcome->diagnostics[0]
            ?? throw new LogicException('Expected the useful-work failure history.');
        Assert::same($workFailure->message, 'Useful work failed.');
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-lifecycle-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to create a lifecycle test file.');
        }

        return $path;
    }
}
