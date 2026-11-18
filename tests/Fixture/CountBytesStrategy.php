<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<FileEntry, array{bytes: int}> */
final readonly class CountBytesStrategy implements Strategy
{
    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{bytes: int}
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $contents = \file_get_contents($work->payload->path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the assigned test file.');
        }

        return ['bytes' => \strlen($contents)];
    }
}
