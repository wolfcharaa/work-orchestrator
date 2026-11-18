<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\Future;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Result\TaskOutcome;

/** @internal */
interface ManagedTaskExecutor
{
    /** @return Future<TaskOutcome<mixed>> */
    public function submit(
        ManagedTask $task,
        EventContext $context,
        Cancellation $cancellation,
    ): Future;
}
