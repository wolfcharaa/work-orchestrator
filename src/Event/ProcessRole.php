<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Event;

enum ProcessRole: string
{
    case Primary = 'primary';
    case Child = 'child';
}
