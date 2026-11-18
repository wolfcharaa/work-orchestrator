<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkerCrashAction;

/** @internal */
final readonly class AbortManagedTaskCrashPolicy implements ManagedTaskCrashPolicy
{
    public function maxAttempts(): int
    {
        return 1;
    }

    public function onCrash(
        ManagedTask $task,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction {
        return WorkerCrashAction::AbortRun;
    }
}
