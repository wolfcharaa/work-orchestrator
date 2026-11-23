<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Event\EventEnvelope;

/** @internal */
interface EventEmitter
{
    public function send(EventEnvelope $envelope): void;
}
