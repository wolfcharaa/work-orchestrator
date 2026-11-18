<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

final readonly class ByteMultiplier
{
    public function __construct(private int $factor) {}

    public function apply(int $bytes): int
    {
        return $bytes * $this->factor;
    }
}
