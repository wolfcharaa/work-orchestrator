<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;

/** Terminal failure plus ordered diagnostics that occurred around it. @internal */
final readonly class FailureEvidence
{
    /**
     * @param list<FailureDiagnostic> $before
     * @param list<FailureDiagnostic> $after
     */
    public function __construct(
        public FailureDiagnostic $terminal,
        public array $before = [],
        public array $after = [],
    ) {}

    /** @return list<FailureDiagnostic> */
    public function history(): array
    {
        return [...$this->before, ...$this->after];
    }

    /** @return list<FailureDiagnostic> */
    public function recoveredHistory(): array
    {
        return [...$this->before, $this->terminal, ...$this->after];
    }
}
