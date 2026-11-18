<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Event\EventContext;

/** @internal */
final class EventScopeState
{
    /** @var non-negative-int */
    private int $sequence = 0;

    public function __construct(public readonly EventContext $context) {}

    /** @return positive-int */
    public function nextSequence(): int
    {
        return ++$this->sequence;
    }
}
