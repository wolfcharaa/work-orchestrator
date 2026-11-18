<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

final readonly class NumberPayload
{
    public function __construct(public int $value) {}
}
