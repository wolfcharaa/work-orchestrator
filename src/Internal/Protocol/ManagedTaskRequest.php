<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Event\EventContext;

/** @internal */
final readonly class ManagedTaskRequest
{
    public function __construct(
        public ManagedTask $task,
        public EventContext $context,
    ) {}
}
