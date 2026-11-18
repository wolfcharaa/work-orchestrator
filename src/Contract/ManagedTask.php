<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/**
 * Small serializable message executed by a named handler.
 *
 * Bulk data should be referenced by path, range, key, or another durable
 * locator instead of being embedded in the task object.
 */
interface ManagedTask
{
    /** @return class-string<ManagedTaskHandler> */
    public function handler(): string;
}
