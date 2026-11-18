<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\WorkResult;

/** Root scheduler output before worker cleanup and final hooks. @internal */
final readonly class ProcessingResult
{
    /**
     * @param list<WorkResult<mixed>> $results
     * @param list<FailureDiagnostic> $diagnostics
     */
    public function __construct(
        public array $results,
        public array $diagnostics,
        public bool $cancelled,
        public bool $failed,
    ) {}
}
