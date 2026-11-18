<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use RuntimeException;

/** Typed failure thrown when a top-level WorkResult value is requested. */
final class WorkFailed extends RuntimeException
{
    public function __construct(public readonly FailureDiagnostic $diagnostic)
    {
        parent::__construct(\sprintf(
            'Work failed with %s: %s',
            $diagnostic->type,
            $diagnostic->message,
        ), $diagnostic->code);
    }
}
