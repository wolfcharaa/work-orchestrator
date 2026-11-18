<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\HandlesTaskFailure;
use Fedor\WorkOrchestrator\FailureAction;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\RetryCurrent;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Throwable;

/** @implements HandlesTaskFailure<FileEntry, int> */
final class RetryingStrategy implements HandlesTaskFailure
{
    private int $attempts = 0;

    public function maxAttempts(): int
    {
        return 2;
    }

    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        ++$this->attempts;
        if ($this->attempts === 1) {
            throw new LogicException('Retry fixture first attempt.');
        }

        return $this->attempts;
    }

    public function onFailure(
        WorkEnvelope $work,
        Throwable $failure,
        int $attempt,
        Cancellation $cancellation,
    ): FailureAction {
        return new RetryCurrent();
    }
}
