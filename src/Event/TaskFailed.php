<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

final readonly class TaskFailed implements TaskLifecycleEvent
{
    public function __construct(
        public ExecutorType $executor,
        public int $attempt,
        public string $failureType,
        public string $message,
    ) {}
}
