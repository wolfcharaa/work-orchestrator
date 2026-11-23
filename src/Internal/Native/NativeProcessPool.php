<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Fedor\WorkOrchestrator\Contract\DeclaresIdempotentWork;
use Fedor\WorkOrchestrator\Contract\IdempotentManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Exception\WorkerBootstrapFailed;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Hook\WorkerStarted;
use Fedor\WorkOrchestrator\Hook\WorkerStopped;
use Fedor\WorkOrchestrator\Internal\AbortManagedTaskCrashPolicy;
use Fedor\WorkOrchestrator\Internal\HookDispatcher;
use Fedor\WorkOrchestrator\Internal\IpcPayloadGuard;
use Fedor\WorkOrchestrator\Internal\PrimaryTaskResult;
use Fedor\WorkOrchestrator\Internal\ProcessingFailure;
use Fedor\WorkOrchestrator\Internal\ProcessingResult;
use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameCodec;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use Fedor\WorkOrchestrator\Internal\Protocol\Handshake;
use Fedor\WorkOrchestrator\Internal\Protocol\InitializeWorker;
use Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest;
use Fedor\WorkOrchestrator\Internal\Protocol\WorkerRole;
use Fedor\WorkOrchestrator\Internal\Protocol\WorkRequest;
use Fedor\WorkOrchestrator\Internal\TaskIdentity;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Exhausted;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\WorkerCrashAction;
use LogicException;
use RuntimeException;
use Throwable;

/** Root owner of every primary and managed worker process in one run. @internal */
final class NativeProcessPool
{
    private readonly ProcessingFailure $failure;

    private readonly ManagedTaskCrashPolicy $managedCrashPolicy;

    /** @var array<int, PrimarySlot> */
    private array $primaries = [];

    /** @var array<int, array<int, ManagedSlot>> */
    private array $managed = [];

    /** @var array<int, WorkResult<mixed>> */
    private array $results = [];

    /** @var list<FailureDiagnostic> */
    private array $diagnostics = [];

    /** @var array<string, true> */
    private array $finalizedWorkers = [];

    /** @var Future<void>|null */
    private ?Future $blocked = null;

    private bool $admissionExhausted = false;

    private bool $cancelled = false;

    private bool $failed = false;

    private bool $cleanupStarted = false;

    private bool $started = false;

    /**
     * @template TPayload
     * @template TResult
     * @param WorkQueue<TPayload> $queue
     * @param Strategy<TPayload, TResult> $strategy
     * @param WorkerCrashPolicy<TPayload> $workerCrashPolicy
     * @param HookDispatcher<TPayload> $hooks
     */
    public function __construct(
        private readonly string $runId,
        private readonly WorkQueue $queue,
        private readonly Strategy $strategy,
        private readonly int $workerLimit,
        private readonly WorkerRuntimeConfiguration $configuration,
        private readonly HookDispatcher $hooks,
        private readonly WorkerCrashPolicy $workerCrashPolicy,
        ?Cancellation $cancellation,
        private readonly string $autoloadPath,
    ) {
        $this->failure = new ProcessingFailure($cancellation);
        $this->managedCrashPolicy = $strategy instanceof ProvidesManagedTaskCrashPolicy
            ? $strategy->managedTaskCrashPolicy()
            : new AbortManagedTaskCrashPolicy();
        if ($this->managedCrashPolicy->maxAttempts() < 1) {
            throw new LogicException('Managed task crash attempt limit must be positive.');
        }
    }

    public function run(): ProcessingResult
    {
        try {
            $this->startAll();
            $this->started = true;

            while (true) {
                $this->hooks->reap();
                $this->pump();
                $this->detectExited();
                $this->assignManaged();
                $this->assignPrimary();

                if ($this->failure->cancellation()->isRequested()) {
                    if ($this->failure->first() === null) {
                        $this->cancelled = true;
                    } else {
                        $this->failed = true;
                    }

                    $this->settleCancellation();

                    break;
                }
                if ($this->isComplete()) {
                    break;
                }

                EventLoopWait::once($this->processes(), $this->failure->cancellation());
            }
        } catch (CancelledException $failure) {
            if ($this->failure->first() === null) {
                $this->cancelled = true;
                $this->settleCancellation();
                if ($this->results === []) {
                    $this->diagnostics[] = FailureDiagnostic::from($failure);
                }
            } else {
                $this->failed = true;
            }
        } catch (Throwable $failure) {
            if (!$this->started) {
                $failure = new WorkerBootstrapFailed($failure);
            }
            $this->diagnostics[] = FailureDiagnostic::from($failure);
            $this->failed = true;
            $this->failure->record($failure);
        } finally {
            $this->cleanup();
        }

        \ksort($this->results);

        return new ProcessingResult(
            \array_values($this->results),
            $this->diagnostics,
            $this->cancelled,
            $this->failed,
        );
    }

    private function startAll(): void
    {
        for ($index = 0; $index < $this->workerLimit; ++$index) {
            $this->primaries[$index] = $this->startPrimary($index);
            $this->managed[$index] = [];
        }
    }

    private function startPrimary(int $index): PrimarySlot
    {
        $process = ChildProcess::spawn(
            WorkerRole::Primary,
            'primary-' . $index,
            $index,
            $this->autoloadPath,
            $this->configuration->maxIpcPayloadBytes,
            $this->configuration->outputTailBytes,
        );
        $slot = new PrimarySlot($index, $process);
        try {
            $this->initializeProcess(
                $process,
                new InitializeWorker(
                    WorkerRole::Primary,
                    $this->strategy,
                    $this->configuration,
                    $index,
                ),
            );
        } catch (Throwable $failure) {
            $process->terminate(9);
            $this->waitUntilStopped($process, 0.2);
            $process->close();

            throw $failure;
        }
        $slot->state = WorkerState::Ready;
        $this->hooks->worker(new WorkerStarted($process->workerId));

        return $slot;
    }

    private function startManaged(int $primaryIndex, int $index): ManagedSlot
    {
        $workerId = 'primary-' . $primaryIndex . '-managed-' . $index;
        $process = ChildProcess::spawn(
            WorkerRole::Managed,
            $workerId,
            $primaryIndex,
            $this->autoloadPath,
            $this->configuration->maxIpcPayloadBytes,
            $this->configuration->outputTailBytes,
        );
        $slot = new ManagedSlot($index, $primaryIndex, $process);
        $bootstrap = $this->strategy instanceof ProvidesManagedTaskBootstrap
            ? $this->strategy->managedTaskBootstrap()
            : null;
        try {
            $this->initializeProcess(
                $process,
                new InitializeWorker(
                    WorkerRole::Managed,
                    $bootstrap,
                    $this->configuration,
                    $primaryIndex,
                ),
            );
        } catch (Throwable $failure) {
            $process->terminate(9);
            $this->waitUntilStopped($process, 0.2);
            $process->close();

            throw $failure;
        }
        $slot->state = WorkerState::Ready;
        $this->hooks->worker(new WorkerStarted($process->workerId));

        return $slot;
    }

    private function initializeProcess(ChildProcess $process, InitializeWorker $initialize): void
    {
        $handshakeFrame = $this->awaitFrame($process);
        if ($handshakeFrame->type !== FrameType::Handshake
            || !$handshakeFrame->payload instanceof Handshake
            || $handshakeFrame->payload->protocolVersion !== FrameCodec::VERSION
            || $handshakeFrame->payload->role !== $process->role
            || $handshakeFrame->payload->workerId !== $process->workerId) {
            throw new RuntimeException(\sprintf(
                'Worker %s returned an invalid protocol handshake.',
                $process->workerId,
            ));
        }

        $process->send(new Frame(FrameType::Initialize, $initialize));
        $ready = $this->awaitFrame($process);
        if ($ready->type === FrameType::Failure && $ready->payload instanceof FailureDiagnostic) {
            throw new RuntimeException(\sprintf(
                'Worker %s bootstrap failed: %s: %s',
                $process->workerId,
                $ready->payload->type,
                $ready->payload->message,
            ));
        }
        if ($ready->type !== FrameType::Ready) {
            throw new RuntimeException(\sprintf('Worker %s did not become ready.', $process->workerId));
        }
    }

    private function awaitFrame(ChildProcess $process): Frame
    {
        while (true) {
            $process->flush();
            $process->drainOutput();
            $frames = $process->readFrames();
            if ($frames !== []) {
                if (\count($frames) !== 1) {
                    throw new RuntimeException(\sprintf(
                        'Worker %s sent unexpected bootstrap frames.',
                        $process->workerId,
                    ));
                }

                return $frames[0];
            }
            if (!$process->isRunning()) {
                throw new RuntimeException($this->exitMessage($process, 'during bootstrap'));
            }

            EventLoopWait::once([$process], $this->failure->cancellation());
        }
    }

    private function pump(): void
    {
        foreach ($this->primaries as $slot) {
            $this->pumpPrimary($slot);
        }
        foreach ($this->managed as $slots) {
            foreach ($slots as $slot) {
                $this->pumpManaged($slot);
            }
        }
    }

    private function pumpPrimary(PrimarySlot $slot): void
    {
        if ($slot->state === WorkerState::Terminated) {
            return;
        }

        $slot->process->flush();
        $slot->process->drainOutput();
        foreach ($slot->process->readFrames() as $frame) {
            if ($frame->type === FrameType::Event && $frame->payload instanceof EventEnvelope) {
                $this->hooks->envelope($frame->payload);

                continue;
            }
            if ($frame->type === FrameType::ExecuteManagedTask
                && $frame->payload instanceof ManagedTaskRequest
                && $frame->requestId !== null) {
                if ($slot->state !== WorkerState::Busy) {
                    throw new RuntimeException('An idle primary requested managed work.');
                }
                $slot->pendingManaged[] = [
                    'requestId' => $frame->requestId,
                    'request' => $frame->payload,
                ];

                continue;
            }
            if ($frame->type === FrameType::WorkResult
                && $frame->payload instanceof PrimaryTaskResult
                && $frame->requestId === $slot->requestId) {
                $this->finishPrimary($slot, $frame->payload);

                continue;
            }
            if ($frame->type === FrameType::Failure && $frame->payload instanceof FailureDiagnostic) {
                $slot->processFailure = $frame->payload;

                continue;
            }
            if ($frame->type === FrameType::Finalized && $this->cleanupStarted) {
                $slot->state = WorkerState::Finalized;

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Primary %s sent unexpected frame %s.',
                $slot->process->workerId,
                $frame->type->value,
            ));
        }
    }

    private function pumpManaged(ManagedSlot $slot): void
    {
        if ($slot->state === WorkerState::Terminated) {
            return;
        }

        $slot->process->flush();
        $slot->process->drainOutput();
        foreach ($slot->process->readFrames() as $frame) {
            if ($frame->type === FrameType::Event && $frame->payload instanceof EventEnvelope) {
                $primary = $this->primaries[$slot->primaryIndex] ?? null;
                if ($primary !== null && $primary->state !== WorkerState::Terminated) {
                    $primary->process->send($frame);
                }

                continue;
            }
            if ($frame->type === FrameType::ManagedTaskResult
                && $frame->payload instanceof TaskOutcome
                && $frame->requestId === $slot->requestId) {
                $primary = $this->primaries[$slot->primaryIndex] ?? null;
                if ($primary !== null && $primary->state === WorkerState::Busy) {
                    $primary->process->send(new Frame(
                        FrameType::ManagedTaskResult,
                        self::withDiagnostics($frame->payload, $slot->diagnostics),
                        $frame->requestId,
                    ));
                }
                $slot->resetTask();

                continue;
            }
            if ($frame->type === FrameType::Failure && $frame->payload instanceof FailureDiagnostic) {
                $slot->processFailure = $frame->payload;

                continue;
            }
            if ($frame->type === FrameType::Finalized && $this->cleanupStarted) {
                $slot->state = WorkerState::Finalized;

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Managed worker %s sent unexpected frame %s.',
                $slot->process->workerId,
                $frame->type->value,
            ));
        }
    }

    private function assignPrimary(): void
    {
        if ($this->admissionExhausted || $this->failure->cancellation()->isRequested()) {
            return;
        }
        if ($this->blocked !== null) {
            if (!$this->blocked->isComplete()) {
                return;
            }
            $this->blocked->await($this->failure->cancellation());
            $this->blocked = null;
        }

        foreach ($this->primaries as $slot) {
            if ($slot->state !== WorkerState::Ready) {
                continue;
            }

            $decision = $this->queue->acquire();
            if ($decision instanceof Exhausted) {
                $this->admissionExhausted = true;

                return;
            }
            if ($decision instanceof Blocked) {
                $cancellation = $this->failure->cancellation();
                $this->blocked = async(static function () use ($decision, $cancellation): void {
                    $decision->await($cancellation);
                });

                return;
            }
            if (!$decision instanceof Granted) {
                throw new LogicException(\sprintf(
                    'Admission queue returned unsupported decision %s.',
                    \get_debug_type($decision),
                ));
            }

            $lease = $decision->lease;
            $taskId = TaskIdentity::create('work');
            $context = $this->primaryContext($lease->work->id, $taskId, 1);
            $this->hooks->beforeTask(new TaskBoundary(
                $this->runId,
                $taskId,
                $lease->work,
                $lease->position,
            ));
            $slot->lease = $lease;
            $slot->context = $context;
            $slot->requestId = $taskId;
            $slot->attempt = 1;
            $slot->state = WorkerState::Busy;

            try {
                $request = new WorkRequest($lease->work, $context);
                IpcPayloadGuard::assertWithin($request, $this->configuration->maxIpcPayloadBytes);
                $slot->process->send(new Frame(FrameType::ExecuteWork, $request, $taskId));
            } catch (Throwable $failure) {
                $outcome = TaskOutcome::failed(FailureDiagnostic::from(
                    $failure,
                    FailureOrigin::task($context, ExecutorType::Primary, 1),
                ));
                $this->finishPrimary($slot, new PrimaryTaskResult($outcome, true));
            }
        }
    }

    private function assignManaged(): void
    {
        foreach ($this->primaries as $primaryIndex => $primary) {
            while ($primary->pendingManaged !== []) {
                if (($this->managed[$primaryIndex] ?? []) === []) {
                    for ($index = 0; $index < $this->configuration->childWorkers; ++$index) {
                        $this->managed[$primaryIndex][$index] = $this->startManaged($primaryIndex, $index);
                    }
                }
                $slot = $this->readyManaged($primaryIndex);
                if ($slot === null) {
                    break;
                }

                $pending = \array_shift($primary->pendingManaged);
                $slot->requestId = $pending['requestId'];
                $slot->request = $pending['request'];
                $slot->attempt = 1;
                $slot->state = WorkerState::Busy;
                $slot->process->send(new Frame(
                    FrameType::ExecuteManagedTask,
                    $slot->request,
                    $slot->requestId,
                ));
            }
        }
    }

    private function readyManaged(int $primaryIndex): ?ManagedSlot
    {
        foreach ($this->managed[$primaryIndex] ?? [] as $slot) {
            if ($slot->state === WorkerState::Ready) {
                return $slot;
            }
        }

        return null;
    }

    private function finishPrimary(PrimarySlot $slot, PrimaryTaskResult $result): void
    {
        $lease = $slot->lease
            ?? throw new LogicException('Primary result has no active lease.');
        $context = $slot->context
            ?? throw new LogicException('Primary result has no active context.');
        $outcome = self::withDiagnostics($result->outcome, $slot->diagnostics);

        $this->queue->complete($lease, $outcome);
        $this->results[$lease->position] = new WorkResult($lease->work->id, $outcome);
        foreach ($outcome->diagnostics as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
        if ($outcome->failure !== null) {
            $this->diagnostics[] = $outcome->failure;
            if ($outcome->status->value === 'cancelled' && $this->failure->first() === null) {
                $this->cancelled = true;
            } else {
                $this->failed = true;
            }
        }
        $this->hooks->afterTask(new TaskBoundary(
            $this->runId,
            $context->taskId,
            $lease->work,
            $lease->position,
            $outcome,
        ));

        $slot->resetWork();
        if ($result->abortRun && $outcome->failure !== null) {
            $this->failure->record(new RuntimeException($outcome->failure->message));
        }
    }

    private function detectExited(): void
    {
        foreach ($this->primaries as $slot) {
            if ($slot->state !== WorkerState::Terminated
                && $slot->state !== WorkerState::Finalized
                && !$slot->process->isRunning()) {
                $this->handlePrimaryExit($slot);
            }
        }
        foreach ($this->managed as $slots) {
            foreach ($slots as $slot) {
                if ($slot->state !== WorkerState::Terminated
                    && $slot->state !== WorkerState::Finalized
                    && !$slot->process->isRunning()) {
                    $this->handleManagedExit($slot);
                }
            }
        }
    }

    private function handlePrimaryExit(PrimarySlot $slot): void
    {
        $wasBusy = $slot->state === WorkerState::Busy && $slot->lease !== null && $slot->context !== null;
        $slot->state = WorkerState::Terminated;
        $this->closeProcess($slot->process, false);

        if (!$wasBusy) {
            $failure = new RuntimeException($this->exitMessage($slot->process, 'while idle'));
            $this->diagnostics[] = FailureDiagnostic::from($failure, kind: FailureKind::WorkerCrash);
            $this->failure->record($failure);

            return;
        }

        $lease = $slot->lease;
        $context = $slot->context;
        $crash = FailureDiagnostic::from(
            new RuntimeException($this->exitMessage($slot->process, 'while executing work')),
            FailureOrigin::unavailableTask($context, ExecutorType::Primary, $slot->attempt),
            FailureKind::WorkerCrash,
        );
        if ($slot->processFailure !== null) {
            $slot->diagnostics[] = $slot->processFailure;
        }

        try {
            $action = $this->workerCrashPolicy->onCrash($lease->work, $crash, $slot->attempt);
            $retry = $action === WorkerCrashAction::RetryOnFreshWorker
                && $slot->attempt < $this->workerCrashPolicy->maxAttempts();
            if ($retry && !$this->isIdempotent($lease->work)) {
                throw new LogicException(\sprintf(
                    'Worker crash retry for "%s" requires an explicit idempotency declaration.',
                    $lease->work->id->value,
                ));
            }
            if (!$retry) {
                $this->finishPrimary($slot, new PrimaryTaskResult(
                    TaskOutcome::failed($crash, $slot->diagnostics),
                    true,
                ));
                $slot->state = WorkerState::Terminated;

                return;
            }

            $slot->diagnostics[] = $crash;
            ++$slot->attempt;
            $slot->process = $this->startPrimary($slot->index)->process;
            $slot->state = WorkerState::Busy;
            $slot->context = $this->primaryContext(
                $lease->work->id,
                $context->taskId,
                $slot->attempt,
            );
            $this->restartManagedForPrimary($slot->index);
            $request = new WorkRequest($lease->work, $slot->context);
            $slot->process->send(new Frame(FrameType::ExecuteWork, $request, $slot->requestId));
        } catch (Throwable $failure) {
            $diagnostic = FailureDiagnostic::from($failure);
            $this->finishPrimary($slot, new PrimaryTaskResult(
                TaskOutcome::failed($diagnostic, [...$slot->diagnostics, $crash]),
                true,
            ));
            $slot->state = WorkerState::Terminated;
        }
    }

    private function handleManagedExit(ManagedSlot $slot): void
    {
        $wasBusy = $slot->state === WorkerState::Busy
            && $slot->request !== null
            && $slot->requestId !== null;
        $slot->state = WorkerState::Terminated;
        $this->closeProcess($slot->process, false);

        if (!$wasBusy) {
            $this->managed[$slot->primaryIndex][$slot->index] = $this->startManaged(
                $slot->primaryIndex,
                $slot->index,
            );

            return;
        }

        $request = $slot->request;
        $requestId = $slot->requestId;
        $crash = FailureDiagnostic::from(
            new RuntimeException($this->exitMessage($slot->process, 'while executing a managed task')),
            FailureOrigin::unavailableTask($request->context, ExecutorType::Parallel, $slot->attempt),
            FailureKind::WorkerCrash,
        );
        if ($slot->processFailure !== null) {
            $slot->diagnostics[] = $slot->processFailure;
        }

        try {
            $action = $this->managedCrashPolicy->onCrash($request->task, $crash, $slot->attempt);
            $retry = $action === WorkerCrashAction::RetryOnFreshWorker
                && $slot->attempt < $this->managedCrashPolicy->maxAttempts();
            if ($retry && !$request->task instanceof IdempotentManagedTask) {
                throw new LogicException('Managed task crash retry requires IdempotentManagedTask.');
            }

            $replacement = $this->startManaged($slot->primaryIndex, $slot->index);
            $this->managed[$slot->primaryIndex][$slot->index] = $replacement;
            if ($retry) {
                $replacement->request = $request;
                $replacement->requestId = $requestId;
                $replacement->attempt = $slot->attempt + 1;
                $replacement->diagnostics = [...$slot->diagnostics, $crash];
                $replacement->state = WorkerState::Busy;
                $replacement->process->send(new Frame(
                    FrameType::ExecuteManagedTask,
                    $request,
                    $requestId,
                ));

                return;
            }

            $this->sendManagedOutcome(
                $slot->primaryIndex,
                $requestId,
                TaskOutcome::failed($crash, $slot->diagnostics),
            );
        } catch (Throwable $failure) {
            $this->sendManagedOutcome(
                $slot->primaryIndex,
                $requestId,
                TaskOutcome::failed(
                    FailureDiagnostic::from($failure),
                    [...$slot->diagnostics, $crash],
                ),
            );
        }
    }

    /**
     * @param non-empty-string $requestId
     * @param TaskOutcome<mixed> $outcome
     */
    private function sendManagedOutcome(int $primaryIndex, string $requestId, TaskOutcome $outcome): void
    {
        $primary = $this->primaries[$primaryIndex] ?? null;
        if ($primary !== null && $primary->state === WorkerState::Busy) {
            $primary->process->send(new Frame(FrameType::ManagedTaskResult, $outcome, $requestId));
        }
    }

    private function restartManagedForPrimary(int $primaryIndex): void
    {
        if (!isset($this->managed[$primaryIndex]) || $this->managed[$primaryIndex] === []) {
            return;
        }

        $slots = $this->managed[$primaryIndex];
        foreach ($slots as $slot) {
            if ($slot->state !== WorkerState::Terminated) {
                $slot->process->terminate();
                $this->waitUntilStopped($slot->process, 0.2);
                if ($slot->process->isRunning()) {
                    $slot->process->terminate(9);
                }
                $this->closeProcess($slot->process, false);
            }
        }
        $this->managed[$primaryIndex] = [];
        for ($index = 0; $index < $this->configuration->childWorkers; ++$index) {
            $this->managed[$primaryIndex][$index] = $this->startManaged($primaryIndex, $index);
        }
    }

    private function isComplete(): bool
    {
        if (!$this->admissionExhausted) {
            return false;
        }
        foreach ($this->primaries as $slot) {
            if ($slot->state === WorkerState::Busy || $slot->pendingManaged !== []) {
                return false;
            }
        }
        foreach ($this->managed as $slots) {
            foreach ($slots as $slot) {
                if ($slot->state === WorkerState::Busy) {
                    return false;
                }
            }
        }

        return true;
    }

    private function cleanup(): void
    {
        if ($this->cleanupStarted) {
            return;
        }
        $this->cleanupStarted = true;
        $normal = !$this->cancelled && $this->failure->first() === null;

        foreach ($this->processes() as $process) {
            if (!$normal) {
                $process->send(new Frame(FrameType::Cancel, 'Root run is stopping.'));
            }
            $process->send(new Frame(FrameType::Finalize));
        }

        $deadline = \microtime(true) + $this->configuration->cancellationGraceSeconds;
        $this->drainUntil($deadline);

        $forced = [];
        foreach ($this->processes() as $process) {
            if ($process->isRunning()) {
                $process->terminate();
                $forced[$process->workerId] = $process;
            }
        }
        $this->drainUntil(\microtime(true) + $this->configuration->forceKillSeconds);
        foreach ($forced as $process) {
            if ($process->isRunning()) {
                $process->terminate(9);
                $this->diagnostics[] = FailureDiagnostic::from(
                    new RuntimeException(\sprintf('Worker %s required SIGKILL.', $process->workerId)),
                    kind: FailureKind::ForcedTermination,
                );
                $this->failed = true;
            }
        }

        foreach ($this->processes() as $process) {
            $this->waitUntilStopped($process, 0.2);
            $this->closeProcess(
                $process,
                isset($this->finalizedWorkers[$process->workerId])
                    && !isset($forced[$process->workerId])
                    && !$process->isRunning(),
            );
        }
    }

    private function drainUntil(float $deadline): void
    {
        while (\microtime(true) < $deadline) {
            $running = false;
            foreach ($this->processes() as $process) {
                try {
                    $process->flush();
                    $process->drainOutput();
                    foreach ($process->readFrames() as $frame) {
                        if ($frame->type === FrameType::Finalized) {
                            $this->finalizedWorkers[$process->workerId] = true;

                            continue;
                        }
                        if ($frame->type === FrameType::Failure
                            && $frame->payload instanceof FailureDiagnostic) {
                            $this->diagnostics[] = FailureDiagnostic::from(
                                new RuntimeException(\sprintf(
                                    '%s: %s',
                                    $frame->payload->type,
                                    $frame->payload->message,
                                )),
                                kind: FailureKind::Cleanup,
                            );
                            $this->failed = true;
                        }
                    }
                } catch (Throwable $failure) {
                    $this->diagnostics[] = FailureDiagnostic::from($failure, kind: FailureKind::Cleanup);
                    $this->failed = true;
                }
                $running = $process->isRunning() || $running;
            }
            if (!$running) {
                return;
            }

            EventLoopWait::once(
                $this->processes(),
                null,
                \max(0.000_1, \min(0.05, $deadline - \microtime(true))),
            );
        }
    }

    private function waitUntilStopped(ChildProcess $process, float $seconds): void
    {
        $deadline = \microtime(true) + $seconds;
        while ($process->isRunning() && \microtime(true) < $deadline) {
            EventLoopWait::once(
                [$process],
                null,
                \max(0.000_1, \min(0.02, $deadline - \microtime(true))),
            );
            $process->drainOutput();
        }
    }

    private function settleCancellation(): void
    {
        foreach ($this->processes() as $process) {
            $process->send(new Frame(FrameType::Cancel, 'Root run was cancelled.'));
        }

        $deadline = \microtime(true) + $this->configuration->cancellationGraceSeconds;
        while (\microtime(true) < $deadline) {
            $this->pump();
            if (!$this->hasBusyWork()) {
                return;
            }
            EventLoopWait::once(
                $this->processes(),
                null,
                \max(0.000_1, \min(0.05, $deadline - \microtime(true))),
            );
        }
    }

    private function hasBusyWork(): bool
    {
        foreach ($this->primaries as $slot) {
            if ($slot->state === WorkerState::Busy) {
                return true;
            }
        }
        foreach ($this->managed as $slots) {
            foreach ($slots as $slot) {
                if ($slot->state === WorkerState::Busy) {
                    return true;
                }
            }
        }

        return false;
    }

    private function closeProcess(ChildProcess $process, bool $clean): void
    {
        $process->drainOutput();
        $process->close();
        $this->hooks->worker(new WorkerStopped($process->workerId, $clean));
    }

    /** @return list<ChildProcess> */
    private function processes(): array
    {
        $processes = [];
        foreach ($this->primaries as $slot) {
            if ($slot->state !== WorkerState::Terminated) {
                $processes[] = $slot->process;
            }
        }
        foreach ($this->managed as $slots) {
            foreach ($slots as $slot) {
                if ($slot->state !== WorkerState::Terminated) {
                    $processes[] = $slot->process;
                }
            }
        }

        return $processes;
    }

    private function primaryContext(
        \Fedor\WorkOrchestrator\WorkId $workId,
        string $taskId,
        int $attempt,
    ): EventContext {
        return new EventContext(
            runId: $this->runId,
            workId: $workId,
            taskId: $taskId,
            parentTaskId: null,
            role: ProcessRole::Primary,
            depth: 1,
            sourceId: $taskId . '-worker-attempt-' . $attempt,
        );
    }

    /** @param \Fedor\WorkOrchestrator\WorkEnvelope<mixed> $work */
    private function isIdempotent(\Fedor\WorkOrchestrator\WorkEnvelope $work): bool
    {
        return $this->strategy instanceof DeclaresIdempotentWork
            && $this->strategy->isIdempotent($work);
    }

    private function exitMessage(ChildProcess $process, string $phase): string
    {
        $parts = [\sprintf('Worker %s exited %s.', $process->workerId, $phase)];
        if ($process->stderrTail() !== '') {
            $parts[] = 'stderr: ' . $process->stderrTail();
        }
        if ($process->stdoutTail() !== '') {
            $parts[] = 'stdout: ' . $process->stdoutTail();
        }

        return \implode(' ', $parts);
    }

    /**
     * @param TaskOutcome<mixed> $outcome
     * @param list<FailureDiagnostic> $diagnostics
     * @return TaskOutcome<mixed>
     */
    private static function withDiagnostics(TaskOutcome $outcome, array $diagnostics): TaskOutcome
    {
        $diagnostics = [...$diagnostics, ...$outcome->diagnostics];

        return match ($outcome->status) {
            \Fedor\WorkOrchestrator\Result\TaskStatus::Succeeded => TaskOutcome::succeeded(
                $outcome->value,
                $diagnostics,
            ),
            \Fedor\WorkOrchestrator\Result\TaskStatus::Failed => TaskOutcome::failed(
                $outcome->failure ?? throw new LogicException('Failed outcome has no cause.'),
                $diagnostics,
            ),
            \Fedor\WorkOrchestrator\Result\TaskStatus::Cancelled => TaskOutcome::cancelled(
                $outcome->failure ?? throw new LogicException('Cancelled outcome has no cause.'),
                $diagnostics,
            ),
        };
    }
}
