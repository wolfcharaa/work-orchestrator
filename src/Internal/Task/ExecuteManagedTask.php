<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Internal\ChildWorkerRuntime;
use Fedor\WorkOrchestrator\Internal\IpcPayloadGuard;
use Fedor\WorkOrchestrator\Internal\ManagedTaskRunner;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\TaskOutcome;

/** @implements Task<TaskOutcome<mixed>, never, EventEnvelope> @internal */
final readonly class ExecuteManagedTask implements Task
{
    public function __construct(
        private ManagedTask $task,
        private EventContext $context,
        private int $maxPayloadBytes,
    ) {}

    /**
     * @param Channel<never, EventEnvelope> $channel
     * @return TaskOutcome<mixed>
     */
    public function run(Channel $channel, Cancellation $cancellation): TaskOutcome
    {
        $binding = ProcessEvents::bind($channel, $this->maxPayloadBytes);

        try {
            $outcome = ManagedTaskRunner::run(
                $this->task,
                $this->context,
                ExecutorType::Parallel,
                $cancellation,
                ChildWorkerRuntime::container(),
                $this->maxPayloadBytes,
            );
            IpcPayloadGuard::assertWithin($outcome, $this->maxPayloadBytes);

            return $outcome;
        } finally {
            $binding->close();
        }
    }
}
