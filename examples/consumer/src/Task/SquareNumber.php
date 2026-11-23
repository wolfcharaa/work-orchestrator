<?php

declare(strict_types=1);

namespace App\WorkOrchestratorQuickStart\Task;

use Fedor\WorkOrchestrator\Contract\ManagedTask;

final readonly class SquareNumber implements ManagedTask
{
    public function __construct(public int $number) {}

    public function handler(): string
    {
        return SquareNumberHandler::class;
    }
}
