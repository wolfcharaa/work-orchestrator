<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

enum ExecutionMode: string
{
    case Concurrent = 'concurrent';
    case Parallel = 'parallel';
}
