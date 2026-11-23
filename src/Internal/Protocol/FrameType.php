<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

/** @internal */
enum FrameType: string
{
    case Handshake = 'handshake';
    case Initialize = 'initialize';
    case Ready = 'ready';
    case ExecuteWork = 'execute_work';
    case WorkResult = 'work_result';
    case ExecuteManagedTask = 'execute_managed_task';
    case ManagedTaskResult = 'managed_task_result';
    case Event = 'event';
    case Cancel = 'cancel';
    case Finalize = 'finalize';
    case Finalized = 'finalized';
    case Failure = 'failure';
}
