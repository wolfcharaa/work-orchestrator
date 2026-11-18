<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

final readonly class RetryScheduled implements TaskLifecycleEvent
{
    public function __construct(
        public ExecutorType $executor,
        public int $nextAttempt,
        public float $delaySeconds,
    ) {}
}
