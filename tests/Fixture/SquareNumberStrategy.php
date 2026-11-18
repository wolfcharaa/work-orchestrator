<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<NumberPayload, int> */
final readonly class SquareNumberStrategy implements Strategy
{
    /** @param WorkEnvelope<NumberPayload> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();

        return $work->payload->value * $work->payload->value;
    }
}
