<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use RuntimeException;

/** Local cancellation cause reconstructed from a worker outcome. */
final class RemoteTaskFailure extends RuntimeException
{
    public function __construct(public readonly FailureDiagnostic $diagnostic)
    {
        parent::__construct(\sprintf(
            'Remote task failed with %s: %s',
            $diagnostic->type,
            $diagnostic->message,
        ), $diagnostic->code);
    }
}
