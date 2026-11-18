<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Hook;

use Throwable;

final readonly class ObserverFailure
{
    public function __construct(
        public string $phase,
        public Throwable $failure,
    ) {}
}
