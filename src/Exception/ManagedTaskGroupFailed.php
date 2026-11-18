<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use RuntimeException;

final class ManagedTaskGroupFailed extends RuntimeException
{
    /** @param non-empty-list<FailureDiagnostic> $failures */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(\sprintf(
            'Managed task group completed with %d failure(s).',
            \count($this->failures),
        ));
    }
}
