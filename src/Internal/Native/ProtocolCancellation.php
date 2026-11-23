<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Closure;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use TypeError;

/** Amp cancellation that observes root cancellation whenever the worker loop runs. @internal */
final class ProtocolCancellation implements Cancellation
{
    private readonly string $watcher;

    private bool $closed = false;

    public function __construct(
        private readonly DeferredCancellation $source,
        private readonly WorkerConnection $connection,
    ) {
        $this->watcher = EventLoop::onReadable(
            $this->connection->protocolRead(),
            function (string $_watcher, mixed $_stream): void {
                try {
                    $this->pump();
                } catch (Throwable $failure) {
                    $this->source->cancel($failure);
                }
            },
        );
    }

    public function subscribe(mixed $callback): string
    {
        if (!$callback instanceof Closure) {
            throw new TypeError('Cancellation callback must be a Closure.');
        }
        $this->pump();

        return $this->source->getCancellation()->subscribe($callback);
    }

    public function unsubscribe(string $id): void
    {
        $this->source->getCancellation()->unsubscribe($id);
    }

    public function isRequested(): bool
    {
        $this->pump();

        return $this->source->getCancellation()->isRequested();
    }

    public function throwIfRequested(): void
    {
        $this->pump();
        $this->source->getCancellation()->throwIfRequested();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        EventLoop::cancel($this->watcher);
    }

    private function pump(): void
    {
        foreach ($this->connection->poll() as $frame) {
            if ($frame->type === FrameType::Cancel) {
                $this->source->cancel(new RuntimeException(
                    \is_string($frame->payload) ? $frame->payload : 'Root requested cancellation.',
                ));

                continue;
            }

            $this->connection->defer($frame);
        }
    }
}
