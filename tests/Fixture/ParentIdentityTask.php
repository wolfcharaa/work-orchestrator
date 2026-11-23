<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\ManagedTask;

final readonly class ParentIdentityTask implements ManagedTask
{
    public function handler(): string
    {
        return ParentIdentityTaskHandler::class;
    }
}
