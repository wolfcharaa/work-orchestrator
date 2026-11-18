<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\FailureAction;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Throwable;

/**
 * Strategy capability for bounded recovery while its primary process lives.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface HandlesTaskFailure extends Strategy
{
    /** Maximum total attempts for one admitted work item; must be positive. */
    public function maxAttempts(): int;

    /** @param WorkEnvelope<TPayload> $work */
    public function onFailure(
        WorkEnvelope $work,
        Throwable $failure,
        int $attempt,
        Cancellation $cancellation,
    ): FailureAction;
}
