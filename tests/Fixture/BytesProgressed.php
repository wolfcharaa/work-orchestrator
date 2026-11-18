<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Fedor\WorkOrchestrator\Contract\ApplicationEvent;

final readonly class BytesProgressed implements ApplicationEvent
{
    public function __construct(public int $bytes) {}
}
