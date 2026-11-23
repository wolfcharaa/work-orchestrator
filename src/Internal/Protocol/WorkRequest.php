<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @internal */
final readonly class WorkRequest
{
    /** @param WorkEnvelope<mixed> $work */
    public function __construct(
        public WorkEnvelope $work,
        public EventContext $context,
    ) {}
}
