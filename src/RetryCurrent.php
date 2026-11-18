<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use InvalidArgumentException;

final readonly class RetryCurrent implements FailureAction
{
    public function __construct(public float $delaySeconds = 0.0)
    {
        if ($this->delaySeconds < 0) {
            throw new InvalidArgumentException('Retry delay must not be negative.');
        }
    }
}
