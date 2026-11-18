<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\ProcessEvents;

/** @internal */
final class EventBindingToken
{
    private bool $closed = false;

    public function __construct(private readonly EventChannelBinding $binding) {}

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ProcessEvents::unbind($this->binding);
    }
}
