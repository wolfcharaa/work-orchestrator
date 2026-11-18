<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

enum WorkerCrashAction: string
{
    case AbortRun = 'abort_run';
    case RetryOnFreshWorker = 'retry_on_fresh_worker';
}
