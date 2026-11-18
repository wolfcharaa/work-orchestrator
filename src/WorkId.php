<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use InvalidArgumentException;

/** Stable identity of one top-level work item within a run. */
final readonly class WorkId
{
    public function __construct(public string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException('Work identifier must not be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
