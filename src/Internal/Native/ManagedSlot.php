<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;

/** Root-owned mutable state for one managed worker associated with a primary. @internal */
final class ManagedSlot
{
    public WorkerState $state = WorkerState::Starting;

    /** @var non-empty-string|null */
    public ?string $requestId = null;

    public ?ManagedTaskRequest $request = null;

    public int $attempt = 1;

    /** @var list<FailureDiagnostic> */
    public array $diagnostics = [];

    public ?FailureDiagnostic $processFailure = null;

    public function __construct(
        public readonly int $index,
        public readonly int $primaryIndex,
        public ChildProcess $process,
    ) {}

    public function resetTask(): void
    {
        $this->requestId = null;
        $this->request = null;
        $this->attempt = 1;
        $this->diagnostics = [];
        $this->processFailure = null;
        $this->state = WorkerState::Ready;
    }
}
