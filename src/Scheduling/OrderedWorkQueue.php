<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * Work-conserving queue that admits tasks in the supplied order.
 *
 * @template TPayload
 * @extends AbstractTaskQueue<TPayload>
 * @implements WorkQueue<TPayload>
 */
final class OrderedWorkQueue extends AbstractTaskQueue implements WorkQueue
{
    /** @var non-empty-list<WorkEnvelope<TPayload>> */
    private readonly array $tasks;

    /** @var non-negative-int */
    private int $next = 0;

    /** @param array<array-key, WorkEnvelope<TPayload>> $work */
    public function __construct(array $work)
    {
        if ($work === [] || !\array_is_list($work)) {
            throw InvalidRunRequest::invalidAdmissionQueue('ordered work must be a non-empty list');
        }

        /** @var non-empty-list<WorkEnvelope<TPayload>> $work */
        $this->tasks = $work;
        self::assertUniqueWorkIds($work);
    }

    public function acquire(): AdmissionDecision
    {
        $position = $this->next;
        if (!isset($this->tasks[$position])) {
            return new Exhausted();
        }

        ++$this->next;

        return new Granted($this->grant($this->tasks[$position], $position));
    }

    /** @param TaskOutcome<mixed> $outcome */
    public function complete(TaskLease $lease, TaskOutcome $outcome): void
    {
        $this->finish($lease, $outcome);
    }

    public function count(): int
    {
        return \count($this->tasks);
    }

    /** @param TaskOutcome<mixed> $outcome */
    protected function onCompleted(TaskLease $lease, TaskOutcome $outcome): void {}
}
