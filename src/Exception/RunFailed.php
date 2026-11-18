<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use Fedor\WorkOrchestrator\Result\RunResult;
use RuntimeException;

final class RunFailed extends RuntimeException
{
    /** @param RunResult<mixed> $result */
    public function __construct(public readonly RunResult $result)
    {
        parent::__construct(\sprintf(
            'Work run finished with status %s and %d diagnostic(s).',
            $result->status->value,
            \count($result->diagnostics()),
        ));
    }
}
