<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkerCrashAction;

/** Primary-local policy for loss of a child worker process. */
interface ManagedTaskCrashPolicy
{
    public function maxAttempts(): int;

    public function onCrash(
        ManagedTask $task,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction;
}
