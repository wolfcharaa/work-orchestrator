<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

/**
 * @template-covariant TPayload
 * @implements AdmissionDecision<TPayload>
 */
final readonly class Granted implements AdmissionDecision
{
    /** @param TaskLease<TPayload> $lease */
    public function __construct(public TaskLease $lease) {}
}
