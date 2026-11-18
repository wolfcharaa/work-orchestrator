<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Internal\WorkerRuntime;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;

/**
 * @implements Task<true, never, never>
 *
 * @internal
 */
final readonly class InitializeWorkerRuntime implements Task
{
    /**
     * @template TPayload
     * @template TResult
     * @param Strategy<TPayload, TResult> $strategy
     */
    public function __construct(
        private Strategy $strategy,
        private WorkerRuntimeConfiguration $configuration,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): true
    {
        $cancellation->throwIfRequested();
        WorkerRuntime::initialize($this->strategy, $this->configuration, $cancellation);
        $cancellation->throwIfRequested();

        return true;
    }
}
