<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkerCrashAction;

/**
 * Root-local policy for loss of a primary worker process.
 *
 * @template TPayload
 */
interface WorkerCrashPolicy
{
    /** Maximum total executions of one work item after worker crashes. */
    public function maxAttempts(): int;

    /** @param WorkEnvelope<TPayload> $work */
    public function onCrash(
        WorkEnvelope $work,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction;
}
