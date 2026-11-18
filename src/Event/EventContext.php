<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;

/** Correlation inherited by every event emitted by one logical task. */
final readonly class EventContext
{
    public function __construct(
        public string $runId,
        public WorkId $workId,
        public string $taskId,
        public ?string $parentTaskId,
        public ProcessRole $role,
        public int $depth,
        public string $sourceId,
    ) {
        if ($this->runId === '' || $this->taskId === '' || $this->sourceId === '') {
            throw new InvalidArgumentException('Event correlation identifiers must not be empty.');
        }

        if ($this->parentTaskId === '') {
            throw new InvalidArgumentException('Parent task identifier must be null or non-empty.');
        }

        if ($this->depth < 1 || $this->depth > 2) {
            throw new InvalidArgumentException('Only primary and child task depths are supported.');
        }
    }
}
