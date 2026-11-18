<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Internal\ChildWorkerRuntime;

/** @implements Task<null, never, never> @internal */
final readonly class FinalizeChildWorkerRuntime implements Task
{
    public function run(Channel $channel, Cancellation $cancellation): null
    {
        ChildWorkerRuntime::finalize();

        return null;
    }
}
