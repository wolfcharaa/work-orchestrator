<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

enum ExecutorType: string
{
    case Primary = 'primary';
    case Concurrent = 'concurrent';
    case Parallel = 'parallel';
}
