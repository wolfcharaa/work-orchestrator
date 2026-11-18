<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * @template TPayload
 *
 * @internal
 */
abstract class AbstractTaskQueue
{
    /** @var array<non-empty-string, TaskLease<TPayload>> */
    private array $inFlight = [];

    /** @var array<non-empty-string, true> */
    private array $completed = [];

    /** @var positive-int */
    private int $nextLease = 1;

    /** @param iterable<array-key, WorkEnvelope<TPayload>> $work */
    final protected static function assertUniqueWorkIds(iterable $work): void
    {
        $seen = [];
        foreach ($work as $item) {
            $key = 'work:' . $item->id->value;
            if (isset($seen[$key])) {
                throw InvalidRunRequest::duplicateWorkId($item->id->value);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param WorkEnvelope<TPayload> $work
     * @param non-negative-int $position
     *
     * @return TaskLease<TPayload>
     */
    final protected function grant(WorkEnvelope $work, int $position): TaskLease
    {
        $id = 'lease-' . $this->nextLease++;
        $lease = new TaskLease($id, $position, $work);
        $this->inFlight[$id] = $lease;

        return $lease;
    }

    /**
     * @param TaskLease<TPayload> $lease
     * @param TaskOutcome<mixed> $outcome
     */
    final protected function finish(TaskLease $lease, TaskOutcome $outcome): void
    {
        if (isset($this->completed[$lease->id])) {
            throw InvalidRunRequest::completedLease($lease->id);
        }

        if (($this->inFlight[$lease->id] ?? null) !== $lease) {
            throw InvalidRunRequest::unknownLease($lease->id);
        }

        unset($this->inFlight[$lease->id]);
        $this->completed[$lease->id] = true;
        $this->onCompleted($lease, $outcome);
    }

    /**
     * @param TaskLease<TPayload> $lease
     * @param TaskOutcome<mixed> $outcome
     */
    abstract protected function onCompleted(TaskLease $lease, TaskOutcome $outcome): void;
}
