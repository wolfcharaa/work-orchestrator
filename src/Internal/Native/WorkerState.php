<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

/** @internal */
enum WorkerState
{
    case Starting;
    case Ready;
    case Busy;
    case Finalizing;
    case Finalized;
    case Terminated;
}
