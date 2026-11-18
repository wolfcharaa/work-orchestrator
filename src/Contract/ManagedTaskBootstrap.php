<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Amp\Cancellation;
use Psr\Container\ContainerInterface;

/** Serializable recipe for one reusable child-process service graph. */
interface ManagedTaskBootstrap
{
    public function boot(Cancellation $cancellation): ContainerInterface;

    public function close(): void;
}
