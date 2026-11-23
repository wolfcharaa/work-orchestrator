<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;

/** Root-owned mutable state for one reusable primary process. @internal */
final class PrimarySlot
{
    public WorkerState $state = WorkerState::Starting;

    /** @var TaskLease<mixed>|null */
    public ?TaskLease $lease = null;

    public ?EventContext $context = null;

    /** @var non-empty-string|null */
    public ?string $requestId = null;

    public int $attempt = 1;

    /** @var list<FailureDiagnostic> */
    public array $diagnostics = [];

    public ?FailureDiagnostic $processFailure = null;

    /** @var list<array{requestId: non-empty-string, request: \Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest}> */
    public array $pendingManaged = [];

    public function __construct(
        public readonly int $index,
        public ChildProcess $process,
    ) {}

    public function resetWork(): void
    {
        $this->lease = null;
        $this->context = null;
        $this->requestId = null;
        $this->attempt = 1;
        $this->diagnostics = [];
        $this->processFailure = null;
        $this->pendingManaged = [];
        $this->state = WorkerState::Ready;
    }
}
