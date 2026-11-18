<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Internal\WorkerRuntime;

/**
 * @implements Task<null, never, never>
 *
 * @internal
 */
final readonly class FinalizeWorkerRuntime implements Task
{
    public function run(Channel $channel, Cancellation $cancellation): null
    {
        WorkerRuntime::finalize();

        return null;
    }
}
