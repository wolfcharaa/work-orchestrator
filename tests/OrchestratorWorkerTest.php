<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use function Amp\async;

use Amp\DeferredCancellation;

use function Amp\delay;

use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\File\FileOrder;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Hook\WorkerStopped;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\Tests\Fixture\BlockingStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\CountBytesStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\DelayedCountBytesStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\FailingCloseStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\FailOnPathStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\LifecycleStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\NumberPayload;
use Fedor\WorkOrchestrator\Tests\Fixture\PartialBootstrapStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\ReturnPathStrategy;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class OrchestratorWorkerTest
{
    public function testTheFacadeDiscoversDirectoryInputsBeforeStartingWorkers(): void
    {
        $directory = \sys_get_temp_dir() . '/work-orchestrator-facade-' . \bin2hex(\random_bytes(8));
        if (!\mkdir($directory)) {
            throw new LogicException('Failed to prepare a facade test directory.');
        }
        $xml = $directory . '/records.XML';
        $json = $directory . '/records.json';
        \file_put_contents($xml, 'abcdef');
        \file_put_contents($json, '{}');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$directory], patterns: ['/\.xml$/i']))
                ->primaryWorkers(1)
                ->run(new CountBytesStrategy());

            Assert::same($result->values(), [['bytes' => 6]]);
        } finally {
            \unlink($xml);
            \unlink($json);
            \rmdir($directory);
        }
    }

    public function testAFileStrategyReceivesTheAdmittedReference(): void
    {
        $path = self::temporaryFile('the Strategy opens this path');

        try {
            Assert::same(
                Orchestrator::fromQueue(FileWorkQueue::discover([$path]))->run(new ReturnPathStrategy())->value(new WorkId($path)),
                $path,
            );
        } finally {
            \unlink($path);
        }
    }

    public function testParallelCompletionDoesNotChangeAdmissionResultOrder(): void
    {
        $first = self::temporaryFile('first');
        $second = self::temporaryFile('second');
        $expected = [$first, $second];
        \sort($expected, \SORT_NATURAL);
        $expectedFirst = $expected[0];
        $expectedSecond = $expected[1]
            ?? throw new LogicException('Expected two ordered input paths.');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$first, $second]))
                ->primaryWorkers(2)
                ->run(new DelayedCountBytesStrategy([
                    $expectedFirst => 0.25,
                    $expectedSecond => 0.0,
                ]));

            Assert::same(\array_map(
                static fn($result): string => $result->id->value,
                $result->results(),
            ), $expected);
        } finally {
            \unlink($first);
            \unlink($second);
        }
    }

    public function testAStrategyFailureStopsAdmissionOfLaterFiles(): void
    {
        $paths = [
            self::temporaryFile('first'),
            self::temporaryFile('second'),
            self::temporaryFile('third'),
            self::temporaryFile('fourth'),
        ];
        $positions = \array_flip($paths);
        $admissionLog = self::temporaryFile('');
        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover(
                $paths,
                order: FileOrder::custom(static fn(FileEntry $left, FileEntry $right): int =>
                    ($positions[$left->path] ?? \PHP_INT_MAX)
                    <=> ($positions[$right->path] ?? \PHP_INT_MAX)),
            ))
                ->primaryWorkers(2)
                ->run(new FailOnPathStrategy($paths[0], $admissionLog, 1.0));

            Assert::same($result->status, RunStatus::Failed);
            $admitted = \file($admissionLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            if ($admitted === false) {
                throw new LogicException('Failed to read the admission log.');
            }

            Assert::true(\in_array($paths[0], $admitted, true));
            Assert::true(!\in_array($paths[2], $admitted, true));
            Assert::true(!\in_array($paths[3], $admitted, true));
        } finally {
            foreach ($paths as $path) {
                \unlink($path);
            }
            \unlink($admissionLog);
        }
    }

    public function testOneWorkerReusesOneBootstrappedStrategyAcrossFiles(): void
    {
        $first = self::temporaryFile('abc');
        $second = self::temporaryFile('12345');
        $lifecycle = self::temporaryFile('');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$first, $second]))
                ->primaryWorkers(1)
                ->run(new LifecycleStrategy($lifecycle, factor: 3));
            $values = \array_map(self::lifecycleResult(...), $result->values());
            $firstValue = $values[0]
                ?? throw new LogicException('Expected the first Strategy result.');
            $secondValue = $values[1]
                ?? throw new LogicException('Expected two Strategy results.');

            Assert::same($firstValue['process'], $secondValue['process']);
            Assert::same([$firstValue['invocation'], $secondValue['invocation']], [1, 2]);
            Assert::same(\file($lifecycle, \FILE_IGNORE_NEW_LINES), [
                'boot:' . $firstValue['process'],
                'close:' . $firstValue['process'],
            ]);
        } finally {
            \unlink($first);
            \unlink($second);
            \unlink($lifecycle);
        }
    }

    public function testItRejectsASecondRunAfterExecutionStarted(): void
    {
        $path = self::temporaryFile('abc');
        $orchestrator = Orchestrator::fromQueue(FileWorkQueue::discover([$path]));

        try {
            $orchestrator->run(new CountBytesStrategy());
            $failure = null;
            try {
                $orchestrator->run(new CountBytesStrategy());
            } catch (Throwable $exception) {
                $failure = $exception;
            }

            Assert::true($failure instanceof InvalidRunRequest);
        } finally {
            \unlink($path);
        }
    }

    public function testAStrategyCloseFailureIsReportedAfterProcessing(): void
    {
        $path = self::temporaryFile('abc');
        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))->run(new FailingCloseStrategy());
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Failed);
        Assert::count($result->diagnostics(), 1);
    }

    public function testAPartialWorkerBootstrapFailureClosesTheInitializedWorker(): void
    {
        $lifecycle = self::temporaryFile('');

        try {
            $result = Orchestrator::fromQueue(new OrderedWorkQueue([
                new WorkEnvelope(new WorkId('first'), new NumberPayload(1)),
                new WorkEnvelope(new WorkId('second'), new NumberPayload(2)),
            ]))
                ->primaryWorkers(2)
                ->run(new PartialBootstrapStrategy($lifecycle));

            $lines = \file($lifecycle, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            Assert::same($result->status, RunStatus::Failed);
            Assert::same($result->results(), []);
            $bootstrapFailure = $result->diagnostics()[0]
                ?? throw new LogicException('Expected a worker bootstrap diagnostic.');
            Assert::same($bootstrapFailure->kind, FailureKind::Bootstrap);
            Assert::true(\is_array($lines));
            Assert::count($lines, 2);
            $boot = $lines[0] ?? throw new LogicException('Expected the worker boot record.');
            $close = $lines[1] ?? throw new LogicException('Expected the worker close record.');
            Assert::true(\str_starts_with($boot, 'boot:'));
            Assert::same($close, 'close:' . \substr($boot, \strlen('boot:')));
        } finally {
            \unlink($lifecycle);
        }
    }

    public function testCancellationAfterRunStartReturnsAPartialCancelledLedger(): void
    {
        $path = self::temporaryFile('cancel');
        $source = new DeferredCancellation();
        async(static function () use ($source): void {
            delay(0.1);
            $source->cancel();
        })->ignore();

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->cancellationGrace(0.5)
                ->run(
                    new DelayedCountBytesStrategy([$path => 1.0]),
                    $source->getCancellation(),
                );
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Cancelled);
        Assert::count($result->results(), 1);
        Assert::count($result->diagnostics(), 1);
    }

    public function testCancellationGraceForcesAnUncooperativePrimaryToStop(): void
    {
        $path = self::temporaryFile('blocking');
        $source = new DeferredCancellation();
        $cleanStops = [];
        async(static function () use ($source): void {
            delay(0.1);
            $source->cancel();
        })->ignore();

        try {
            $startedAt = \microtime(true);
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$path]))
                ->cancellationGrace(0.1)
                ->forceKillAfter(0.1)
                ->onWorkerLifecycle(static function (object $event) use (&$cleanStops): void {
                    if ($event instanceof WorkerStopped) {
                        $cleanStops[] = $event->clean;
                    }
                })
                ->run(new BlockingStrategy(), $source->getCancellation());
            $duration = \microtime(true) - $startedAt;
        } finally {
            \unlink($path);
        }

        Assert::same($result->status, RunStatus::Cancelled);
        Assert::same($cleanStops, [false]);
        Assert::true($duration < 1.0);
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-worker-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to prepare a worker test file.');
        }

        return $path;
    }

    /** @return array{bytes: int, invocation: int, process: int} */
    private static function lifecycleResult(mixed $value): array
    {
        if (!\is_array($value)
            || !isset($value['bytes'], $value['invocation'], $value['process'])
            || !\is_int($value['bytes'])
            || !\is_int($value['invocation'])
            || !\is_int($value['process'])) {
            throw new LogicException('Lifecycle Strategy returned an invalid result.');
        }

        return [
            'bytes' => $value['bytes'],
            'invocation' => $value['invocation'],
            'process' => $value['process'],
        ];
    }
}
