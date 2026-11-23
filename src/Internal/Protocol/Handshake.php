<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

/** @internal */
final readonly class Handshake
{
    public function __construct(
        public int $protocolVersion,
        public WorkerRole $role,
        public string $workerId,
        public int $processId,
    ) {}
}
