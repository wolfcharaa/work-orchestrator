<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use RuntimeException;
use Throwable;

final class WorkerCleanupFailed extends RuntimeException
{
    /**
     * @param non-empty-list<Throwable> $cleanupFailures
     */
    public function __construct(
        public readonly ?Throwable $primaryFailure,
        public readonly array $cleanupFailures,
    ) {
        parent::__construct(
            'Worker cleanup failed after a work run.',
            0,
            $primaryFailure ?? $cleanupFailures[0],
        );
    }
}
