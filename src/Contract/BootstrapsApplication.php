<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Amp\Cancellation;

/**
 * Strategy capability that owns process-local primary-worker resources.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface BootstrapsApplication extends Strategy
{
    /** Creates resources after the primary worker has started. */
    public function boot(Cancellation $cancellation): void;

    /** Releases resources created by boot(); implementations must be idempotent. */
    public function close(): void;
}
