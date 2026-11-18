<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\WorkerCrashAction;

final readonly class RetryManagedTaskCrashPolicy implements ManagedTaskCrashPolicy
{
    public function maxAttempts(): int
    {
        return 2;
    }

    public function onCrash(
        ManagedTask $task,
        FailureDiagnostic $failure,
        int $attempt,
    ): WorkerCrashAction {
        return WorkerCrashAction::RetryOnFreshWorker;
    }
}
