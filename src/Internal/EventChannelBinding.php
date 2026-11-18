<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Sync\Channel;
use Fedor\WorkOrchestrator\Event\EventEnvelope;

/** @internal */
final readonly class EventChannelBinding
{
    /** @param Channel<never, EventEnvelope> $channel */
    public function __construct(
        private Channel $channel,
        private int $maxPayloadBytes,
    ) {}

    public function send(EventEnvelope $envelope): void
    {
        IpcPayloadGuard::assertWithin($envelope, $this->maxPayloadBytes);
        $this->channel->send($envelope);
    }
}
