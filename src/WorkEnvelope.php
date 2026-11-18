<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

/**
 * Scheduler identity paired with an application-owned serializable payload.
 *
 * @template-covariant TPayload
 */
final readonly class WorkEnvelope
{
    /** @param TPayload $payload */
    public function __construct(
        public WorkId $id,
        public mixed $payload,
    ) {}
}
