<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements BootstrapsApplication<FileEntry, array<string, int>> */
final class LifecycleStrategy implements BootstrapsApplication
{
    private ?ByteMultiplier $multiplier = null;

    private int $invocations = 0;

    public function __construct(
        private readonly string $lifecyclePath,
        private readonly int $factor,
    ) {}

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        $this->multiplier = new ByteMultiplier($this->factor);
        self::append($this->lifecyclePath, 'boot:' . self::processId());
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{bytes: int, invocation: int, process: int}
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $multiplier = $this->multiplier
            ?? throw new LogicException('Lifecycle Strategy is not bootstrapped.');
        $bytes = \filesize($work->payload->path);
        if ($bytes === false) {
            throw new LogicException('Failed to inspect a Strategy input file.');
        }

        return [
            'bytes' => $multiplier->apply($bytes),
            'invocation' => ++$this->invocations,
            'process' => self::processId(),
        ];
    }

    public function close(): void
    {
        if ($this->multiplier === null) {
            return;
        }

        $this->multiplier = null;
        self::append($this->lifecyclePath, 'close:' . self::processId());
    }

    private static function processId(): int
    {
        $process = \getmypid();
        if ($process === false) {
            throw new LogicException('Failed to identify a worker process.');
        }

        return $process;
    }

    private static function append(string $path, string $line): void
    {
        if (\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX) === false) {
            throw new LogicException('Failed to record the worker lifecycle.');
        }
    }
}
