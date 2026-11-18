<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\ExecutionMode;

/** Chooses isolation for each managed task without changing its contract. */
interface TaskExecutionPolicy
{
    public function modeFor(ManagedTask $task): ExecutionMode;
}
