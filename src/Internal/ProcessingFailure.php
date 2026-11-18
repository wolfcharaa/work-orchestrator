<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Throwable;

/**
 * Owns sibling cancellation and the primary failure for one processing run.
 *
 * @internal
 */
final class ProcessingFailure
{
    private readonly DeferredCancellation $stop;

    private readonly Cancellation $cancellation;

    private ?Throwable $first = null;

    public function __construct(?Cancellation $parent)
    {
        $this->stop = new DeferredCancellation();
        $this->cancellation = new CompositeCancellation(
            $parent ?? new NullCancellation(),
            $this->stop->getCancellation(),
        );
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    public function first(): ?Throwable
    {
        return $this->first;
    }

    public function record(Throwable $failure): void
    {
        if ($this->first !== null) {
            return;
        }

        $this->first = $failure;
        $this->stop->cancel($failure);
    }
}
