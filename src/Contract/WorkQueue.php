<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\AdmissionDecision;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;

/**
 * Finite root-local source and admission policy for top-level work.
 *
 * The queue owns task order and any admission barriers. A granted task remains
 * in-flight until its exact lease is completed. Implementations must reject an
 * unknown or already completed lease instead of silently corrupting scheduler
 * state.
 *
 * @template TPayload
 */
interface WorkQueue
{
    /** @return AdmissionDecision<TPayload> */
    public function acquire(): AdmissionDecision;

    /**
     * @param TaskLease<TPayload> $lease
     * @param TaskOutcome<mixed> $outcome
     */
    public function complete(TaskLease $lease, TaskOutcome $outcome): void;

    /** Number of tasks represented by this queue, including completed tasks. */
    public function count(): int;
}
