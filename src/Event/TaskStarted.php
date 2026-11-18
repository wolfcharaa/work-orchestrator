<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

final readonly class TaskStarted implements TaskLifecycleEvent
{
    public function __construct(
        public ExecutorType $executor,
        public int $attempt,
    ) {}
}
