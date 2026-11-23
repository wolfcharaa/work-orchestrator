<?php

declare(strict_types=1);

namespace App\WorkOrchestratorQuickStart\Task;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use LogicException;

final readonly class SquareNumberHandler implements ManagedTaskHandler
{
    public function handle(ManagedTask $task, Cancellation $cancellation): int
    {
        if (!$task instanceof SquareNumber) {
            throw new LogicException('SquareNumberHandler received an unsupported task.');
        }

        $cancellation->throwIfRequested();

        return $task->number * $task->number;
    }
}
