<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\IdempotentManagedTask;

final readonly class CrashChildTask implements IdempotentManagedTask
{
    public function __construct(public string $marker) {}

    public function handler(): string
    {
        return CrashChildHandler::class;
    }
}
