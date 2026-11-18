<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

final readonly class WorkerStarted implements WorkerLifecycle
{
    public function __construct(private string $id) {}

    public function workerId(): string
    {
        return $this->id;
    }
}
