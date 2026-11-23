<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Event\EventEnvelope;

/** @internal */
final readonly class EventChannelBinding
{
    public function __construct(
        private EventEmitter $emitter,
        private int $maxPayloadBytes,
    ) {}

    public function send(EventEnvelope $envelope): void
    {
        IpcPayloadGuard::assertWithin($envelope, $this->maxPayloadBytes);
        $this->emitter->send($envelope);
    }
}
