<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/**
 * Strategy capability receiving a fresh structured group for each work item.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface UsesManagedTasks extends Strategy
{
    public function useManagedTasks(ManagedTaskGroup $tasks): void;
}
