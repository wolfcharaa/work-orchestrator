<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Future;

/** @internal */
final readonly class TrackedHookFuture
{
    /** @param Future<mixed> $future */
    public function __construct(
        public Future $future,
        public string $phase,
        public bool $notify,
    ) {}
}
