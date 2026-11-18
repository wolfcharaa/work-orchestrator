<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

use Amp\DeferredFuture;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * Priority-barrier queue: every task in a group finishes before the next group
 * may be admitted. Tasks within the open group remain work-conserving.
 *
 * @template TPayload
 * @extends AbstractTaskQueue<TPayload>
 * @implements WorkQueue<TPayload>
 */
final class GroupedWorkQueue extends AbstractTaskQueue implements WorkQueue
{
    /** @var non-empty-list<non-empty-list<WorkEnvelope<TPayload>>> */
    private readonly array $groups;

    /** @var list<WorkEnvelope<TPayload>> */
    private readonly array $opportunistic;

    /** @var non-negative-int */
    private int $group = 0;

    /** @var non-negative-int */
    private int $nextInGroup = 0;

    /** @var non-negative-int */
    private int $inFlightInGroup = 0;

    /** @var non-negative-int */
    private int $nextPosition = 0;

    /** @var non-negative-int */
    private int $nextOpportunistic = 0;

    /** @var array<non-empty-string, true> */
    private array $opportunisticLeases = [];

    /** @var DeferredFuture<void> */
    private DeferredFuture $readiness;

    /**
     * @param array<array-key, array<array-key, WorkEnvelope<TPayload>>> $groups
     * @param array<array-key, WorkEnvelope<TPayload>>                  $opportunistic
     */
    public function __construct(array $groups, array $opportunistic = [])
    {
        if ($groups === [] || !\array_is_list($groups)) {
            throw InvalidRunRequest::invalidAdmissionQueue('task groups must be a non-empty list');
        }

        $allWork = [];
        foreach ($groups as $tasks) {
            if ($tasks === [] || !\array_is_list($tasks)) {
                throw InvalidRunRequest::invalidAdmissionQueue('every task group must be a non-empty list');
            }

            $allWork = [...$allWork, ...$tasks];
        }

        if (!\array_is_list($opportunistic)) {
            throw InvalidRunRequest::invalidAdmissionQueue('opportunistic work must be a list');
        }

        self::assertUniqueWorkIds([...$allWork, ...$opportunistic]);

        /** @var non-empty-list<non-empty-list<WorkEnvelope<TPayload>>> $groups */
        $this->groups = $groups;
        /** @var list<WorkEnvelope<TPayload>> $opportunistic */
        $this->opportunistic = $opportunistic;
        $this->readiness = new DeferredFuture();
    }

    public function acquire(): AdmissionDecision
    {
        $tasks = $this->groups[$this->group] ?? null;
        if ($tasks === null) {
            $opportunistic = $this->opportunistic[$this->nextOpportunistic] ?? null;
            if ($opportunistic === null) {
                return new Exhausted();
            }

            ++$this->nextOpportunistic;

            return new Granted($this->grantOpportunistic($opportunistic));
        }

        if ($this->nextInGroup < \count($tasks)) {
            $task = $this->taskAt($tasks, $this->nextInGroup++);
            ++$this->inFlightInGroup;

            return new Granted($this->grant($task, $this->nextPosition++));
        }

        if ($this->inFlightInGroup > 0) {
            $opportunistic = $this->opportunistic[$this->nextOpportunistic] ?? null;
            if ($opportunistic !== null) {
                ++$this->nextOpportunistic;

                return new Granted($this->grantOpportunistic($opportunistic));
            }

            return new Blocked($this->readiness->getFuture());
        }

        $this->openNextGroup();

        return $this->acquire();
    }

    /** @param TaskOutcome<mixed> $outcome */
    public function complete(TaskLease $lease, TaskOutcome $outcome): void
    {
        $this->finish($lease, $outcome);
    }

    public function count(): int
    {
        return \array_sum(\array_map(\count(...), $this->groups)) + \count($this->opportunistic);
    }

    /** @param TaskOutcome<mixed> $outcome */
    protected function onCompleted(TaskLease $lease, TaskOutcome $outcome): void
    {
        if (isset($this->opportunisticLeases[$lease->id])) {
            unset($this->opportunisticLeases[$lease->id]);

            return;
        }

        if ($this->inFlightInGroup < 1) {
            throw new \LogicException('Grouped queue has no in-flight task to complete.');
        }

        --$this->inFlightInGroup;
        if ($this->inFlightInGroup === 0) {
            $this->openNextGroup();
        }
    }

    private function openNextGroup(): void
    {
        ++$this->group;
        $this->nextInGroup = 0;
        $readiness = $this->readiness;
        $this->readiness = new DeferredFuture();
        $readiness->complete();
    }

    /**
     * @param non-empty-list<WorkEnvelope<TPayload>> $tasks
     * @return WorkEnvelope<TPayload>
     */
    private function taskAt(array $tasks, int $position): WorkEnvelope
    {
        foreach ($tasks as $index => $task) {
            if ($index === $position) {
                return $task;
            }
        }

        throw new \LogicException('Grouped task position is not available.');
    }

    /**
     * @param WorkEnvelope<TPayload> $work
     * @return TaskLease<TPayload>
     */
    private function grantOpportunistic(WorkEnvelope $work): TaskLease
    {
        $lease = $this->grant($work, $this->nextPosition++);
        $this->opportunisticLeases[$lease->id] = true;

        return $lease;
    }
}
