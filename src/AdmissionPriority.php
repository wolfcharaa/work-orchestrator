<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use InvalidArgumentException;

/** Strict barrier priority or admission into the independent filler lane. */
final readonly class AdmissionPriority
{
    private function __construct(public ?int $strict)
    {
        if ($this->strict !== null && $this->strict < 0) {
            throw new InvalidArgumentException('Strict admission priority must not be negative.');
        }
    }

    public static function strict(int $priority): self
    {
        return new self($priority);
    }

    public static function opportunistic(): self
    {
        return new self(null);
    }

    public function isOpportunistic(): bool
    {
        return $this->strict === null;
    }
}
