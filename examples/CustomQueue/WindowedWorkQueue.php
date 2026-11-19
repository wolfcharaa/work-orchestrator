<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\CustomQueue;

use Amp\DeferredFuture;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\AdmissionDecision;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Exhausted;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * Example extension that caps admitted-but-incomplete top-level work.
 *
 * The production queues intentionally keep their shared base class internal.
 * A consumer queue therefore owns and validates its complete lease protocol.
 *
 * @template TPayload
 * @implements WorkQueue<TPayload>
 */
final class WindowedWorkQueue implements WorkQueue
{
    /** @var non-empty-list<WorkEnvelope<TPayload>> */
    private readonly array $work;

    /** @var array<non-empty-string, TaskLease<TPayload>> */
    private array $inFlight = [];

    /** @var array<non-empty-string, true> */
    private array $completed = [];

    /** @var non-negative-int */
    private int $nextPosition = 0;

    /** @var positive-int */
    private int $nextLease = 1;

    /** @var DeferredFuture<void> */
    private DeferredFuture $readiness;

    /**
     * @param array<array-key, WorkEnvelope<TPayload>> $work
     */
    public function __construct(array $work, private readonly int $maxInFlight)
    {
        if ($work === [] || !\array_is_list($work)) {
            throw InvalidRunRequest::invalidAdmissionQueue('windowed work must be a non-empty list');
        }
        if ($this->maxInFlight < 1) {
            throw InvalidRunRequest::invalidConcurrencyLimit($this->maxInFlight);
        }

        $seen = [];
        foreach ($work as $item) {
            $key = 'work:' . $item->id->value;
            if (isset($seen[$key])) {
                throw InvalidRunRequest::duplicateWorkId($item->id->value);
            }
            $seen[$key] = true;
        }

        $this->work = $work;
        $this->readiness = new DeferredFuture();
    }

    public function acquire(): AdmissionDecision
    {
        $work = $this->work[$this->nextPosition] ?? null;
        if ($work === null) {
            return new Exhausted();
        }
        if (\count($this->inFlight) >= $this->maxInFlight) {
            return new Blocked($this->readiness->getFuture());
        }

        $position = $this->nextPosition++;
        $id = 'windowed-lease-' . $this->nextLease++;
        $lease = new TaskLease($id, $position, $work);
        $this->inFlight[$id] = $lease;

        return new Granted($lease);
    }

    public function complete(TaskLease $lease, TaskOutcome $outcome): void
    {
        if (isset($this->completed[$lease->id])) {
            throw InvalidRunRequest::completedLease($lease->id);
        }
        if (($this->inFlight[$lease->id] ?? null) !== $lease) {
            throw InvalidRunRequest::unknownLease($lease->id);
        }

        unset($this->inFlight[$lease->id]);
        $this->completed[$lease->id] = true;

        // Every waiter shares the old Future. Rotate first, then wake all of them.
        $readiness = $this->readiness;
        $this->readiness = new DeferredFuture();
        $readiness->complete();
    }

    public function count(): int
    {
        return \count($this->work);
    }
}
