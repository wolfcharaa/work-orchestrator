<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\ManagedTasks;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\ProvidesTaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\TaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/**
 * @implements UsesManagedTasks<NumberBatch, int>
 * @implements ProvidesTaskExecutionPolicy<NumberBatch, int>
 */
final class SumSquaresStrategy implements
    UsesManagedTasks,
    ProvidesTaskExecutionPolicy,
    TaskExecutionPolicy
{
    private ?ManagedTaskGroup $tasks = null;

    public function __construct(private readonly ExecutionMode $mode) {}

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function taskExecutionPolicy(): TaskExecutionPolicy
    {
        return $this;
    }

    public function modeFor(ManagedTask $task): ExecutionMode
    {
        return $this->mode;
    }

    /** @param WorkEnvelope<NumberBatch> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $tasks = $this->tasks
            ?? throw new LogicException('Managed task group was not provided.');
        $handles = [];
        foreach ($work->payload->numbers as $number) {
            $handles[] = $tasks->submit(new SquareNumber($number));
        }

        $sum = 0;
        foreach ($handles as $handle) {
            $value = $handle->value($cancellation);
            if (!\is_int($value)) {
                throw new LogicException('Square task returned a non-integer value.');
            }
            $sum += $value;
        }

        return $sum;
    }
}
