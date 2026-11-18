<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** Root-local depth-one admission or terminal boundary. */
/** @template TPayload */
final readonly class TaskBoundary
{
    /** @param WorkEnvelope<TPayload> $work */
    public function __construct(
        public string $runId,
        public string $taskId,
        public WorkEnvelope $work,
        public int $position,
        /** @var TaskOutcome<mixed>|null */
        public ?TaskOutcome $outcome = null,
    ) {}
}
