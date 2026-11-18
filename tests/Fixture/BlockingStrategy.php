<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<FileEntry, string> */
final readonly class BlockingStrategy implements Strategy
{
    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        \usleep(5_000_000);

        return $work->payload->path;
    }
}
