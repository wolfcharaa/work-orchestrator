<?php

declare(strict_types=1);

namespace App\WorkOrchestratorQuickStart\Work;

use Amp\Cancellation;
use App\WorkOrchestratorQuickStart\Task\SquareNumber;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements UsesManagedTasks<array{int, int, int, int}, int> */
final class SumSquaresStrategy implements UsesManagedTasks
{
    private ?ManagedTaskGroup $tasks = null;

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    /** @param WorkEnvelope<array{int, int, int, int}> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $tasks = $this->tasks ?? throw new LogicException('Managed task group was not provided.');
        $sum = 0;
        foreach ($work->payload as $number) {
            $value = $tasks->submit(new SquareNumber($number))->value($cancellation);
            if (!\is_int($value)) {
                throw new LogicException('Square task returned a non-integer value.');
            }
            $sum += $value;
        }

        return $sum;
    }
}
