<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;

/** Serializable static limits installed in every primary process. @internal */
final readonly class WorkerRuntimeConfiguration
{
    public function __construct(
        public int $childWorkers,
        public int $concurrentTasks,
        public int $maxIpcPayloadBytes,
        public float $cancellationGraceSeconds = 5.0,
        public float $forceKillSeconds = 2.0,
        public int $outputTailBytes = 16_384,
    ) {
        if ($this->childWorkers < 1) {
            throw InvalidRunRequest::invalidConcurrencyLimit($this->childWorkers);
        }
        if ($this->concurrentTasks < 1) {
            throw InvalidRunRequest::invalidConcurrencyLimit($this->concurrentTasks);
        }
        if ($this->maxIpcPayloadBytes < 1) {
            throw new InvalidRunRequest('Maximum IPC payload must be positive.');
        }
        if ($this->cancellationGraceSeconds < 0) {
            throw new InvalidRunRequest('Cancellation grace period must not be negative.');
        }
        if ($this->forceKillSeconds < 0) {
            throw new InvalidRunRequest('Force-kill period must not be negative.');
        }
        if ($this->outputTailBytes < 1) {
            throw new InvalidRunRequest('Worker output tail limit must be positive.');
        }
    }
}
