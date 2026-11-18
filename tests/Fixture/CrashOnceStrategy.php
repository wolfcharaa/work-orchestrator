<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\DeclaresIdempotentWork;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements DeclaresIdempotentWork<FileEntry, string> */
final readonly class CrashOnceStrategy implements DeclaresIdempotentWork
{
    public function __construct(private string $marker) {}

    /** @param WorkEnvelope<FileEntry> $work */
    public function isIdempotent(WorkEnvelope $work): bool
    {
        return true;
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        if (!\is_file($this->marker)) {
            if (\file_put_contents($this->marker, 'crashed', \LOCK_EX) === false) {
                throw new LogicException('Failed to create the worker crash marker.');
            }

            exit(17);
        }

        return $work->payload->path;
    }
}
