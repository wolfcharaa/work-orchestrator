<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\ManagedTask;

final readonly class NestedValueTask implements ManagedTask
{
    public function __construct(public int $value) {}

    public function handler(): string
    {
        return NestedValueHandler::class;
    }
}
