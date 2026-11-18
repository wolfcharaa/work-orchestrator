<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkerCrashAction;

/** @implements WorkerCrashPolicy<FileEntry> */
final readonly class RetryWorkerCrashPolicy implements WorkerCrashPolicy
{
    public function maxAttempts(): int
    {
        return 2;
    }

    public function onCrash(
        WorkEnvelope $work,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction {
        return WorkerCrashAction::RetryOnFreshWorker;
    }
}
