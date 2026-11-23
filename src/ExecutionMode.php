<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

/** Selects Fiber-local execution or isolation in a root-owned child process. */
enum ExecutionMode: string
{
    case Concurrent = 'concurrent';
    case Parallel = 'parallel';
}
