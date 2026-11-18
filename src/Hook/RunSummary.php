<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\RunStatus;

final readonly class RunSummary
{
    /**
     * @param list<WorkResult<mixed>> $results
     * @param list<FailureDiagnostic> $diagnostics
     */
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public array $results,
        public array $diagnostics,
        public float $startedAt,
        public float $finishedAt,
    ) {}

    public function durationSeconds(): float
    {
        return $this->finishedAt - $this->startedAt;
    }
}
