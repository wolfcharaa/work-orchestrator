<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

enum TaskStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
