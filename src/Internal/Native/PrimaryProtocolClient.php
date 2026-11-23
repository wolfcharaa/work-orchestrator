<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Fedor\WorkOrchestrator\ChildEventRoute;
use Fedor\WorkOrchestrator\Contract\ApplicationEvent;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\RoutesChildApplicationEvents;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Internal\ManagedTaskExecutor;
use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use RuntimeException;
use Throwable;

/** Routes root-owned managed-task responses while a primary Strategy runs. @internal */
final class PrimaryProtocolClient implements ManagedTaskExecutor
{
    /** @var array<non-empty-string, DeferredFuture<TaskOutcome<mixed>>> */
    private array $pending = [];

    /** @var Future<void>|null */
    private ?Future $pump = null;

    /** @param RoutesChildApplicationEvents<mixed, mixed>|null $eventRouter */
    public function __construct(
        private readonly WorkerConnection $connection,
        private readonly DeferredCancellation $stop,
        private readonly ?RoutesChildApplicationEvents $eventRouter,
    ) {}

    /** @return Future<TaskOutcome<mixed>> */
    public function submit(
        ManagedTask $task,
        EventContext $context,
        Cancellation $cancellation,
    ): Future {
        $requestId = $context->taskId;
        if ($requestId === '') {
            throw new RuntimeException('Managed task request identifier must not be empty.');
        }
        if (isset($this->pending[$requestId])) {
            throw new RuntimeException(\sprintf('Managed task request "%s" is already pending.', $requestId));
        }

        $completion = self::completion();
        $this->pending[$requestId] = $completion;
        $this->connection->send(new Frame(
            FrameType::ExecuteManagedTask,
            new ManagedTaskRequest($task, $context),
            $requestId,
        ));
        $this->ensurePump();

        return async(static fn(): TaskOutcome => $completion->getFuture()->await($cancellation));
    }

    /** @return DeferredFuture<TaskOutcome<mixed>> */
    private static function completion(): DeferredFuture
    {
        return new DeferredFuture();
    }

    private function ensurePump(): void
    {
        if ($this->pump !== null && !$this->pump->isComplete()) {
            return;
        }

        $this->pump = async(function (): void {
            try {
                while ($this->pending !== []) {
                    $this->route($this->connection->receive());
                }
            } catch (Throwable $failure) {
                foreach ($this->pending as $completion) {
                    $completion->error($failure);
                }
                $this->pending = [];
            }
        });
    }

    private function route(Frame $frame): void
    {
        if ($frame->type === FrameType::Cancel) {
            $reason = new RuntimeException(
                \is_string($frame->payload) ? $frame->payload : 'Root requested cancellation.',
            );
            $this->stop->cancel($reason);
            throw new CancelledException($reason);
        }
        if ($frame->type === FrameType::Event) {
            $envelope = $frame->payload;
            if (!$envelope instanceof \Fedor\WorkOrchestrator\Event\EventEnvelope) {
                throw new RuntimeException('Managed worker event frame has invalid payload.');
            }
            if (!$envelope->event instanceof ApplicationEvent) {
                ProcessEvents::relay($envelope);

                return;
            }

            $route = $this->eventRouter?->routeChildApplicationEvent($envelope)
                ?? ChildEventRoute::Relay;
            if ($route === ChildEventRoute::Relay) {
                ProcessEvents::relay($envelope);
            }

            return;
        }
        if ($frame->type !== FrameType::ManagedTaskResult || $frame->requestId === null) {
            throw new RuntimeException(\sprintf(
                'Primary worker received unexpected frame %s while awaiting a managed task.',
                $frame->type->value,
            ));
        }
        if (!$frame->payload instanceof TaskOutcome) {
            throw new RuntimeException('Managed task result frame has invalid payload.');
        }

        $completion = $this->pending[$frame->requestId] ?? null;
        if ($completion === null) {
            throw new RuntimeException(\sprintf(
                'Managed task result "%s" has no pending request.',
                $frame->requestId,
            ));
        }
        unset($this->pending[$frame->requestId]);
        $completion->complete($frame->payload);
    }
}
