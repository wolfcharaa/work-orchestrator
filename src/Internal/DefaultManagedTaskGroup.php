<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\TaskExecutionPolicy;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Exception\ManagedTaskGroupFailed;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\TaskHandle;
use LogicException;

/** One task-local structured group spanning concurrent and parallel executors. @internal */
final class DefaultManagedTaskGroup implements ManagedTaskGroup
{
    /** @var list<TaskHandle> */
    private array $handles = [];

    private bool $joined = false;

    private readonly DeferredCancellation $stop;

    private readonly Cancellation $cancellation;

    /** @var list<FailureDiagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly EventContext $parent,
        Cancellation $cancellation,
        private readonly TaskExecutionPolicy $policy,
        private readonly ManagedTaskExecutor $concurrent,
        private readonly ManagedTaskExecutor $parallel,
        private readonly int $maxPayloadBytes,
    ) {
        $this->stop = new DeferredCancellation();
        $this->cancellation = new CompositeCancellation(
            $cancellation,
            $this->stop->getCancellation(),
        );
    }

    public function submit(ManagedTask $task): TaskHandle
    {
        if ($this->joined) {
            throw new LogicException('Cannot submit a task after its managed group was joined.');
        }

        IpcPayloadGuard::assertWithin($task, $this->maxPayloadBytes);
        $taskId = TaskIdentity::create('task');
        $mode = $this->policy->modeFor($task);
        $context = new EventContext(
            runId: $this->parent->runId,
            workId: $this->parent->workId,
            taskId: $taskId,
            parentTaskId: $this->parent->taskId,
            role: $mode === ExecutionMode::Parallel ? ProcessRole::Child : ProcessRole::Primary,
            depth: 2,
            sourceId: $taskId,
        );
        $executor = $mode === ExecutionMode::Parallel ? $this->parallel : $this->concurrent;
        $handle = new TaskHandle(
            $taskId,
            $executor->submit($task, $context, $this->cancellation),
        );
        $this->handles[] = $handle;

        return $handle;
    }

    public function join(): void
    {
        if ($this->joined) {
            return;
        }

        $this->joined = true;
        $failures = [];
        foreach ($this->handles as $handle) {
            $outcome = $handle->outcome();
            foreach ($outcome->diagnostics as $diagnostic) {
                $this->diagnostics[] = $diagnostic;
            }
            if ($outcome->failure !== null) {
                $failures[] = $outcome->failure;
            }
        }

        if ($failures !== []) {
            /** @var non-empty-list<FailureDiagnostic> $failures */
            throw new ManagedTaskGroupFailed($failures);
        }
    }

    public function cancel(\Throwable $cause): void
    {
        $this->stop->cancel($cause);
    }

    /** @return list<FailureDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
