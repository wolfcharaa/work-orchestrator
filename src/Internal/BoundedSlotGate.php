<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;

/** Cancellation-aware bounded admission for one event-loop process. @internal */
final class BoundedSlotGate
{
    private int $active = 0;

    /** @var DeferredFuture<void> */
    private DeferredFuture $readiness;

    public function __construct(private readonly int $limit)
    {
        if ($this->limit < 1) {
            throw InvalidRunRequest::invalidConcurrencyLimit($this->limit);
        }

        $this->readiness = new DeferredFuture();
    }

    public function acquire(Cancellation $cancellation): void
    {
        while ($this->active >= $this->limit) {
            $this->readiness->getFuture()->await($cancellation);
        }

        $cancellation->throwIfRequested();
        ++$this->active;
    }

    public function release(): void
    {
        if ($this->active < 1) {
            throw new \LogicException('Cannot release an inactive bounded slot.');
        }

        --$this->active;
        $readiness = $this->readiness;
        $this->readiness = new DeferredFuture();
        $readiness->complete();
    }
}
