<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

/** @internal */
enum WorkerRole: string
{
    case Primary = 'primary';
    case Managed = 'managed';
}
