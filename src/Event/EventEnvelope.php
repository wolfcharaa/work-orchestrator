<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

use Fedor\WorkOrchestrator\Contract\ApplicationEvent;
use Fedor\WorkOrchestrator\WorkId;

/** Serializable transport envelope with per-source FIFO sequence metadata. */
final readonly class EventEnvelope
{
    /** @param positive-int $sequence */
    public function __construct(
        public string $runId,
        public WorkId $workId,
        public string $taskId,
        public ?string $parentTaskId,
        public ProcessRole $role,
        public int $depth,
        public string $sourceId,
        public int $sequence,
        public float $emittedAt,
        public int $processId,
        public CoreEvent|ApplicationEvent $event,
    ) {}

    public function isTaskLifecycle(): bool
    {
        return $this->event instanceof TaskLifecycleEvent;
    }
}
