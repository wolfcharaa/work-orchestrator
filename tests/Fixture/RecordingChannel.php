<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Internal\EventEmitter;

final class RecordingChannel implements EventEmitter
{
    /** @var list<EventEnvelope> */
    public array $sent = [];

    public function send(EventEnvelope $message): void
    {
        $this->sent[] = $message;
    }
}
