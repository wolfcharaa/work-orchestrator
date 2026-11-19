<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\Basic;

/** Small control-plane message; the worker receives an identifier, not a customer record. */
final readonly class RebuildCustomer
{
    public function __construct(public int $customerId) {}
}
