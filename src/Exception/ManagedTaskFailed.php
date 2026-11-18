<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use RuntimeException;

final class ManagedTaskFailed extends RuntimeException
{
    /** @param list<FailureDiagnostic> $diagnostics */
    public function __construct(
        public readonly FailureDiagnostic $diagnostic,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct(\sprintf(
            'Managed task failed with %s: %s',
            $diagnostic->type,
            $diagnostic->message,
        ), $diagnostic->code);
    }
}
