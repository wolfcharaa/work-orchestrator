<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

use Amp\Cancellation;
use Amp\Future;

/**
 * Admission is temporarily blocked by in-flight work.
 *
 * Await readiness before acquiring again; the Future is shared by every
 * scheduler lane blocked on the same queue state.
 *
 * @implements AdmissionDecision<never>
 */
final readonly class Blocked implements AdmissionDecision
{
    /** @param Future<void> $readiness */
    public function __construct(private Future $readiness) {}

    public function await(?Cancellation $cancellation = null): void
    {
        $this->readiness->await($cancellation);
    }
}
