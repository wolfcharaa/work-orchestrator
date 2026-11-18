<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\HandlesTaskFailure;
use Fedor\WorkOrchestrator\FailCurrent;
use Fedor\WorkOrchestrator\FailureAction;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Throwable;

/** @implements HandlesTaskFailure<FileEntry, string> */
final readonly class ContinueAfterFailureStrategy implements HandlesTaskFailure
{
    public function __construct(private string $failurePath) {}

    public function maxAttempts(): int
    {
        return 1;
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        if ($work->payload->path === $this->failurePath) {
            throw new LogicException('Expected non-aborting file failure.');
        }

        return $work->payload->path;
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function onFailure(
        WorkEnvelope $work,
        Throwable $failure,
        int $attempt,
        Cancellation $cancellation,
    ): FailureAction {
        return new FailCurrent();
    }
}
