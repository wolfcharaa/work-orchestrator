<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Internal\ChildWorkerRuntime;

/** @implements Task<true, never, never> @internal */
final readonly class InitializeChildWorkerRuntime implements Task
{
    public function __construct(private ?ManagedTaskBootstrap $bootstrap) {}

    public function run(Channel $channel, Cancellation $cancellation): true
    {
        ChildWorkerRuntime::initialize($this->bootstrap, $cancellation);

        return true;
    }
}
