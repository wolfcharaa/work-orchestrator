<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\WorkEnvelope;

/**
 * Application-owned work policy executed inside a primary worker.
 *
 * The object crosses the process boundary once for each primary worker. Keep
 * constructor state serializable and create process-local resources through
 * optional capability interfaces such as BootstrapsApplication and
 * UsesManagedTasks.
 *
 * @template TPayload
 * @template-covariant TResult
 */
interface Strategy
{
    /**
     * @param WorkEnvelope<TPayload> $work
     * @return TResult
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): mixed;
}
