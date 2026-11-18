<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/**
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface ProvidesTaskExecutionPolicy extends Strategy
{
    public function taskExecutionPolicy(): TaskExecutionPolicy;
}
