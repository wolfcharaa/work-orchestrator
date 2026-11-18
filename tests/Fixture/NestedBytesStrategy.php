<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/**
 * @implements BootstrapsApplication<FileEntry, array<string, mixed>>
 * @implements UsesManagedTasks<FileEntry, array<string, mixed>>
 */
final class NestedBytesStrategy implements BootstrapsApplication, UsesManagedTasks
{
    private ?ByteMultiplier $multiplier = null;

    private ?ManagedTaskGroup $tasks = null;

    public function __construct(
        private readonly string $lifecyclePath,
        private readonly int $factor,
    ) {}

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        $this->multiplier = new ByteMultiplier($this->factor);
        self::appendLifecycle($this->lifecyclePath, 'boot:' . self::processId());
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{bytes: int, primaryProcess: int, children: list<array{process: int, value: int}>}
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $multiplier = $this->multiplier
            ?? throw new LogicException('Expected a process-local ByteMultiplier.');
        $tasks = $this->tasks
            ?? throw new LogicException('Expected a task-local managed group.');
        $bytes = $multiplier->apply(self::fileSize($work));
        $handles = [];
        foreach ([$bytes, $bytes + 1, $bytes + 2] as $value) {
            $handles[] = $tasks->submit(new NestedValueTask($value));
        }

        $children = [];
        foreach ($handles as $handle) {
            $result = $handle->value($cancellation);
            if (!\is_array($result)
                || !isset($result['process'], $result['value'])
                || !\is_int($result['process'])
                || !\is_int($result['value'])) {
                throw new LogicException('Managed task returned an invalid fixture result.');
            }
            $children[] = [
                'process' => $result['process'],
                'value' => $result['value'],
            ];
        }

        return [
            'bytes' => $bytes,
            'primaryProcess' => self::processId(),
            'children' => $children,
        ];
    }

    public function close(): void
    {
        if ($this->multiplier === null) {
            return;
        }

        $this->multiplier = null;
        $this->tasks = null;
        self::appendLifecycle($this->lifecyclePath, 'close:' . self::processId());
    }

    /** @param WorkEnvelope<FileEntry> $work */
    private static function fileSize(WorkEnvelope $work): int
    {
        $bytes = \filesize($work->payload->path);
        if ($bytes === false) {
            throw new LogicException('Failed to inspect a Strategy input file.');
        }

        return $bytes;
    }

    private static function processId(): int
    {
        $process = \getmypid();
        if ($process === false) {
            throw new LogicException('Failed to identify a worker process.');
        }

        return $process;
    }

    private static function appendLifecycle(string $path, string $line): void
    {
        if (\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX) === false) {
            throw new LogicException('Failed to record the Strategy lifecycle.');
        }
    }
}
