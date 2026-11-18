<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\HandlesTaskFailure;
use Fedor\WorkOrchestrator\FailureAction;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Throwable;

/** @implements HandlesTaskFailure<FileEntry, int> */
final readonly class FailurePolicyThrowsStrategy implements HandlesTaskFailure
{
    public function maxAttempts(): int
    {
        return 2;
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        throw new LogicException('Useful work failed.');
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function onFailure(
        WorkEnvelope $work,
        Throwable $failure,
        int $attempt,
        Cancellation $cancellation,
    ): FailureAction {
        throw new LogicException('Failure policy failed.');
    }
}
