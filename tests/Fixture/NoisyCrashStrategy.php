<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<FileEntry, never> */
final readonly class NoisyCrashStrategy implements Strategy
{
    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): never
    {
        \fwrite(\STDOUT, \str_repeat('o', 262_144) . 'OUT-END');
        \fwrite(\STDERR, \str_repeat('e', 262_144) . 'ERR-END');

        exit(23);
    }
}
