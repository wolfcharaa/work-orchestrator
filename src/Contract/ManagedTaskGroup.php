<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\TaskHandle;

/** Task-local structured group; submitted work cannot outlive its parent. */
interface ManagedTaskGroup
{
    public function submit(ManagedTask $task): TaskHandle;

    /** Waits for every submitted task and seals the group. */
    public function join(): void;
}
