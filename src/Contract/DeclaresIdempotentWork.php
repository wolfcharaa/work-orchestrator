<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * Explicit safety assertion required before retrying work after process loss.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface DeclaresIdempotentWork extends Strategy
{
    /** @param WorkEnvelope<TPayload> $work */
    public function isIdempotent(WorkEnvelope $work): bool;
}
