<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Amp\Cancellation;

/** Process-local implementation of one ManagedTask message. */
interface ManagedTaskHandler
{
    public function handle(ManagedTask $task, Cancellation $cancellation): mixed;
}
