<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\TaskExecutionPolicy;
use Fedor\WorkOrchestrator\ExecutionMode;

/** Unknown task code is isolated in a process by default. @internal */
final readonly class DefaultTaskExecutionPolicy implements TaskExecutionPolicy
{
    public function modeFor(ManagedTask $task): ExecutionMode
    {
        return ExecutionMode::Parallel;
    }
}
