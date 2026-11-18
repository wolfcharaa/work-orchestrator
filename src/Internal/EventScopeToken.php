<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\ProcessEvents;

/** @internal */
final class EventScopeToken
{
    private bool $closed = false;

    public function __construct(private readonly ?EventScopeState $previous) {}

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ProcessEvents::restoreScope($this->previous);
    }
}
