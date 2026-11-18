<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<string, int> */
final readonly class StringLengthStrategy implements Strategy
{
    /** @param WorkEnvelope<string> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        return \strlen($work->payload);
    }
}
