<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/**
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface ProvidesManagedTaskCrashPolicy extends Strategy
{
    public function managedTaskCrashPolicy(): ManagedTaskCrashPolicy;
}
