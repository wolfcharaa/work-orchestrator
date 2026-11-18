<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

final readonly class RunContext
{
    public function __construct(
        public string $runId,
        public int $taskCount,
        public int $primaryWorkerLimit,
        public float $startedAt,
    ) {}
}
