<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements BootstrapsApplication<FileEntry, string> */
final readonly class FailingCloseStrategy implements BootstrapsApplication
{
    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        return $work->payload->path;
    }

    public function close(): void
    {
        throw new LogicException('Strategy close failed.');
    }
}
