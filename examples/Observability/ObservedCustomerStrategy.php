<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\Observability;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomer;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<RebuildCustomer, int> */
final readonly class ObservedCustomerStrategy implements Strategy
{
    /** @param WorkEnvelope<RebuildCustomer> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();
        ProcessEvents::emit(new CustomerRebuilt($work->payload->customerId));

        return $work->payload->customerId;
    }
}
