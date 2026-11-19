<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\Observability;

use Fedor\WorkOrchestrator\Contract\ApplicationEvent;

/** Application-owned fact emitted from a worker and observed in root. */
final readonly class CustomerRebuilt implements ApplicationEvent
{
    public function __construct(public int $customerId) {}
}
