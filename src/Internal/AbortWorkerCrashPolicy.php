<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkerCrashAction;

/**
 * Safe default: a process may have produced unknown partial side effects.
 *
 * @implements WorkerCrashPolicy<mixed>
 * @internal
 */
final readonly class AbortWorkerCrashPolicy implements WorkerCrashPolicy
{
    public function maxAttempts(): int
    {
        return 1;
    }

    /** @param WorkEnvelope<mixed> $work */
    public function onCrash(
        WorkEnvelope $work,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction {
        return WorkerCrashAction::AbortRun;
    }
}
