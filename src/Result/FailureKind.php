<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

/** Stable failure classification that survives a process boundary. */
enum FailureKind: string
{
    case Application = 'application';
    case Cancellation = 'cancellation';
    case WorkerCrash = 'worker_crash';
    case Bootstrap = 'bootstrap';
    case Cleanup = 'cleanup';
    case Transport = 'transport';
    case Observer = 'observer';
    case ForcedTermination = 'forced_termination';
}
