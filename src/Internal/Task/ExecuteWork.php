<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Internal\PrimaryTaskResult;
use Fedor\WorkOrchestrator\Internal\WorkerRuntime;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * @implements Task<PrimaryTaskResult, never, EventEnvelope>
 *
 * @internal
 */
final readonly class ExecuteWork implements Task
{
    /** @param WorkEnvelope<mixed> $work */
    public function __construct(
        private WorkEnvelope $work,
        private EventContext $context,
        private int $maxPayloadBytes,
    ) {}

    /** @param Channel<never, EventEnvelope> $channel */
    public function run(Channel $channel, Cancellation $cancellation): PrimaryTaskResult
    {
        $binding = ProcessEvents::bind($channel, $this->maxPayloadBytes);
        $scope = ProcessEvents::enter($this->context);

        try {
            return WorkerRuntime::execute(
                $this->work,
                $this->context,
                $cancellation,
            );
        } finally {
            $scope->close();
            $binding->close();
        }
    }
}
