<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;

/** @internal */
final readonly class InitializeWorker
{
    /** @param Strategy<mixed, mixed>|ManagedTaskBootstrap|null $application */
    public function __construct(
        public WorkerRole $role,
        public object|null $application,
        public WorkerRuntimeConfiguration $configuration,
        public int $primaryIndex,
    ) {}
}
