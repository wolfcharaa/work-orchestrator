<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

final readonly class WorkerStopped implements WorkerLifecycle
{
    public function __construct(
        private string $id,
        public bool $clean,
    ) {}

    public function workerId(): string
    {
        return $this->id;
    }
}
