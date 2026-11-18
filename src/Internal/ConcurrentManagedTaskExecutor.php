<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use function Amp\async;

use Amp\Cancellation;
use Amp\Future;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Psr\Container\ContainerInterface;

/** Bounded Fiber executor inside a primary worker. @internal */
final class ConcurrentManagedTaskExecutor implements ManagedTaskExecutor
{
    private readonly BoundedSlotGate $slots;

    public function __construct(
        int $limit,
        private readonly ?ContainerInterface $container,
        private readonly int $maxPayloadBytes,
    ) {
        $this->slots = new BoundedSlotGate($limit);
    }

    /** @return Future<TaskOutcome<mixed>> */
    public function submit(
        ManagedTask $task,
        EventContext $context,
        Cancellation $cancellation,
    ): Future {
        $this->slots->acquire($cancellation);

        return async(function () use ($task, $context, $cancellation): TaskOutcome {
            try {
                return ManagedTaskRunner::run(
                    $task,
                    $context,
                    ExecutorType::Concurrent,
                    $cancellation,
                    $this->container,
                    $this->maxPayloadBytes,
                );
            } finally {
                $this->slots->release();
            }
        });
    }
}
