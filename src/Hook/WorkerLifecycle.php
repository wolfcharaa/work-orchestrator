<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

interface WorkerLifecycle
{
    public function workerId(): string;
}
