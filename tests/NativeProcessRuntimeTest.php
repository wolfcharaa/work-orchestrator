<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use function Amp\async;
use function Amp\delay;

use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Hook\WorkerLifecycle;
use Fedor\WorkOrchestrator\Hook\WorkerStarted;
use Fedor\WorkOrchestrator\Hook\WorkerStopped;
use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameCodec;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameParser;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Tests\Fixture\DelayedCountBytesStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\NestedBytesStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\NoisyCrashStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\ParentIdentityStrategy;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class NativeProcessRuntimeTest
{
    public function testProtocolParserAcceptsEverySingleSplitPoint(): void
    {
        $expected = new Frame(FrameType::Ready, ['worker' => 1], 'request-1');
        $encoded = FrameCodec::encode($expected, 1_048_576);

        for ($split = 1; $split < \strlen($encoded); ++$split) {
            $parser = new FrameParser(1_048_576);
            Assert::same($parser->push(\substr($encoded, 0, $split)), []);
            $frames = $parser->push(\substr($encoded, $split));
            Assert::count($frames, 1);
            $frame = $frames[0] ?? throw new LogicException('Expected one decoded frame.');
            Assert::same($frame->type, FrameType::Ready);
            Assert::same($frame->requestId, 'request-1');
            Assert::same($frame->payload, ['worker' => 1]);
        }
    }

    public function testProtocolRejectsAnOversizedHeaderBeforeDecode(): void
    {
        $parser = new FrameParser(32);
        $failure = null;
        try {
            $parser->push(\pack('a4nN', 'WOR1', FrameCodec::VERSION, 33));
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        Assert::true($failure instanceof \Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded);
    }

    public function testProtocolParserAcceptsMultipleFramesInOneRead(): void
    {
        $first = new Frame(FrameType::Ready, 'first', 'request-1');
        $second = new Frame(FrameType::Finalized, 'second', 'request-2');
        $parser = new FrameParser(1_048_576);

        $frames = $parser->push(
            FrameCodec::encode($first, 1_048_576)
            . FrameCodec::encode($second, 1_048_576),
        );

        Assert::count($frames, 2);
        $decodedFirst = $frames[0] ?? throw new LogicException('Expected the first frame.');
        $decodedSecond = $frames[1] ?? throw new LogicException('Expected the second frame.');
        Assert::same($decodedFirst->requestId, 'request-1');
        Assert::same($decodedSecond->requestId, 'request-2');
        Assert::true(!$parser->hasPartialFrame());
    }

    public function testProtocolRejectsInvalidMagicVersionAndLength(): void
    {
        foreach ([
            \pack('a4nN', 'BAD!', FrameCodec::VERSION, 1),
            \pack('a4nN', 'WOR1', FrameCodec::VERSION + 1, 1),
            \pack('a4nN', 'WOR1', FrameCodec::VERSION, 0),
        ] as $header) {
            $failure = null;
            try {
                (new FrameParser(1_048_576))->push($header);
            } catch (Throwable $exception) {
                $failure = $exception;
            }

            Assert::true($failure instanceof \RuntimeException);
        }
    }

    public function testProtocolRetainsATruncatedFrameUntilMoreBytesArrive(): void
    {
        $encoded = FrameCodec::encode(new Frame(FrameType::Ready), 1_048_576);
        $parser = new FrameParser(1_048_576);

        Assert::same($parser->push(\substr($encoded, 0, -1)), []);
        Assert::true($parser->hasPartialFrame());
        Assert::count($parser->push(\substr($encoded, -1)), 1);
        Assert::true(!$parser->hasPartialFrame());
    }

    public function testRootOwnsBalancedPrimaryAndLazyManagedWorkerLifecycles(): void
    {
        $input = self::temporaryFile('abc');
        $lifecycle = self::temporaryFile('');
        $started = [];
        $stopped = [];
        $cleanStops = [];

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->childWorkersPerPrimary(2)
                ->onWorkerLifecycle(static function (WorkerLifecycle $event) use (&$started, &$stopped, &$cleanStops): void {
                    if ($event instanceof WorkerStarted) {
                        $started[] = $event->workerId();
                    }
                    if ($event instanceof WorkerStopped) {
                        $stopped[] = $event->workerId();
                        $cleanStops[] = $event->clean;
                    }
                })
                ->run(new NestedBytesStrategy($lifecycle, 2));

            \sort($started);
            \sort($stopped);
            Assert::same($result->status, RunStatus::Succeeded);
            Assert::same($started, [
                'primary-0',
                'primary-0-managed-0',
                'primary-0-managed-1',
            ]);
            Assert::same($stopped, $started);
            Assert::same($cleanStops, [true, true, true]);
        } finally {
            \unlink($input);
            \unlink($lifecycle);
        }
    }

    public function testRootIsTheDirectParentOfPrimaryAndManagedWorkers(): void
    {
        if (!\function_exists('posix_getppid')) {
            return;
        }

        $rootProcess = \getmypid();
        if ($rootProcess === false) {
            throw new LogicException('Failed to identify the test root process.');
        }
        $input = self::temporaryFile('topology');

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->run(new ParentIdentityStrategy());
            $value = $result->value(new WorkId($input));
        } finally {
            \unlink($input);
        }

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($value['primary']['parent'], $rootProcess);
        Assert::same($value['managed']['parent'], $rootProcess);
        Assert::true($value['primary']['process'] !== $value['managed']['process']);
    }

    public function testRunCooperatesWithAnAlreadyActiveEventLoop(): void
    {
        $input = self::temporaryFile('event-loop');
        $ticks = 0;
        $succeeded = false;

        try {
            $progress = async(static function () use (&$ticks): void {
                for ($index = 0; $index < 5; ++$index) {
                    delay(0.01);
                    ++$ticks;
                }
            });
            $run = async(static function () use ($input, &$succeeded): void {
                $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                    ->run(new DelayedCountBytesStrategy([$input => 0.08]));
                $succeeded = $result->status === RunStatus::Succeeded;
            });

            $run->await();
            $progress->await();
        } finally {
            \unlink($input);
        }

        Assert::true($succeeded);
        Assert::same($ticks, 5);
    }

    public function testWorkerOutputFloodIsDrainedAndOnlyBoundedTailsReachTheDiagnostic(): void
    {
        $input = self::temporaryFile('output-flood');

        try {
            $startedAt = \microtime(true);
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$input]))
                ->workerOutputTailBytes(64)
                ->run(new NoisyCrashStrategy());
            $duration = \microtime(true) - $startedAt;
        } finally {
            \unlink($input);
        }

        $work = $result->result(new WorkId($input));
        $failure = $work?->outcome->failure
            ?? throw new LogicException('Expected a worker-crash diagnostic.');
        Assert::same($result->status, RunStatus::Failed);
        Assert::true($duration < 2.0);
        Assert::true(\str_contains($failure->message, 'ERR-END'));
        Assert::true(\str_contains($failure->message, 'OUT-END'));
        Assert::true(\strlen($failure->message) < 256);
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-native-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to prepare a native runtime fixture.');
        }

        return $path;
    }
}
