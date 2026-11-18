<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;

/** Strategy value plus recovered child diagnostics for one attempt. @internal */
final readonly class AttemptResult
{
    /** @param list<FailureDiagnostic> $diagnostics */
    public function __construct(
        public mixed $value,
        public array $diagnostics,
    ) {}
}
