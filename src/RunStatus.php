<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

enum RunStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
