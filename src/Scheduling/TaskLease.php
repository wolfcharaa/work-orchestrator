<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * One atomic grant from a WorkQueue.
 *
 * @template-covariant TPayload
 */
final readonly class TaskLease
{
    /**
     * @param non-empty-string $id
     * @param non-negative-int $position
     * @param WorkEnvelope<TPayload> $work
     */
    public function __construct(
        public string $id,
        public int $position,
        public WorkEnvelope $work,
    ) {}
}
