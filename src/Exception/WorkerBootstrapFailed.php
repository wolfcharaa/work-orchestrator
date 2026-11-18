<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use RuntimeException;
use Throwable;

/** Primary worker creation or process-local Strategy bootstrap failed. */
final class WorkerBootstrapFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Primary worker bootstrap failed.', previous: $previous);
    }
}
