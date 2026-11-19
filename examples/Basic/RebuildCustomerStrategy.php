<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\Basic;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<RebuildCustomer, int> */
final readonly class RebuildCustomerStrategy implements Strategy
{
    /** @param WorkEnvelope<RebuildCustomer> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();

        // A real application would load and rebuild this customer here.
        return $work->payload->customerId;
    }
}
