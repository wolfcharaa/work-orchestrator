<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/**
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface ProvidesManagedTaskBootstrap extends Strategy
{
    public function managedTaskBootstrap(): ManagedTaskBootstrap;
}
