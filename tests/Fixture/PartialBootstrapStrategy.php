<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements BootstrapsApplication<NumberPayload, int> */
final class PartialBootstrapStrategy implements BootstrapsApplication
{
    private bool $booted = false;

    public function __construct(private readonly string $lifecyclePath) {}

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        $handle = \fopen($this->lifecyclePath, 'c+');
        if ($handle === false) {
            throw new LogicException('Failed to open the partial bootstrap lifecycle file.');
        }

        try {
            if (!\flock($handle, \LOCK_EX)) {
                throw new LogicException('Failed to lock the partial bootstrap lifecycle file.');
            }
            if (!\rewind($handle)) {
                throw new LogicException('Failed to rewind the partial bootstrap lifecycle file.');
            }

            $contents = \stream_get_contents($handle);
            if ($contents === false) {
                throw new LogicException('Failed to read the partial bootstrap lifecycle file.');
            }
            if ($contents !== '') {
                throw new LogicException('Only the first worker may bootstrap successfully.');
            }

            $processId = \getmypid();
            if ($processId === false || \fwrite($handle, 'boot:' . $processId . "\n") === false) {
                throw new LogicException('Failed to record the successful worker bootstrap.');
            }
            \fflush($handle);
            $this->booted = true;
        } finally {
            \flock($handle, \LOCK_UN);
            \fclose($handle);
        }
    }

    /** @param WorkEnvelope<NumberPayload> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        throw new LogicException('Work must not start after a partial bootstrap failure.');
    }

    public function close(): void
    {
        if (!$this->booted) {
            return;
        }

        $this->booted = false;
        $processId = \getmypid();
        if ($processId === false
            || \file_put_contents(
                $this->lifecyclePath,
                'close:' . $processId . "\n",
                \FILE_APPEND | \LOCK_EX,
            ) === false) {
            throw new LogicException('Failed to record the successful worker cleanup.');
        }
    }
}
