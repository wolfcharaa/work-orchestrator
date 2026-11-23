<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use Amp\Cancellation;
use Closure;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Contract\WorkerCrashPolicy;
use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Internal\AbortWorkerCrashPolicy;
use Fedor\WorkOrchestrator\Internal\NativeWorkRunner;
use Fedor\WorkOrchestrator\Internal\RootHooks;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;
use Fedor\WorkOrchestrator\Result\RunResult;

/**
 * One-shot root coordinator for finite application-owned work.
 *
 * The facade owns admission, process limits, cancellation, live event drain,
 * root-local observers, and cleanup. Strategy owns useful work and
 * optional capabilities such as bounded failure recovery, managed child tasks,
 * application bootstrap, and task execution policy.
 *
 * The queue supplies stable WorkEnvelope identities and controls ordering or
 * barriers. The Strategy is serialized once per primary worker and processes
 * admitted payloads inside that process. Example:
 *
 *     $queue = new OrderedWorkQueue([
 *         new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
 *     ]);
 *
 *     $result = Orchestrator::fromQueue($queue)
 *         ->primaryWorkers(4)
 *         ->run(new RebuildCustomerStrategy());
 *
 *     $value = $result->value(new WorkId('customer:42'));
 *
 * Use File\FileWorkQueue when application payloads are files. Core itself does
 * not discover, parse, split, checkpoint, or persist files. An instance is
 * one-shot: configuration is immutable after run() starts and a second run is
 * rejected.
 *
 * Root configuration includes primaryWorkers(), childWorkersPerPrimary(),
 * concurrentTasksPerPrimary(), maxIpcPayloadBytes(), cancellationGrace(),
 * forceKillAfter(), workerOutputTailBytes(), workerAutoloader(), and
 * workerCrashPolicy(). Root-local lifecycle and application observers are
 * registered through the before/on/after hook methods; observer failures are
 * isolated from useful work and retained in RunResult. Strategy capability
 * interfaces opt into process-local bootstrap, managed tasks, bounded failure
 * recovery, handler containers, child event routing, and execution policy.
 *
 * @template TPayload
 */
final class Orchestrator
{
    /** @var positive-int */
    private int $primaryWorkers = 1;

    /** @var positive-int */
    private int $childWorkers = 1;

    /** @var positive-int */
    private int $concurrentTasks = 1;

    /** @var positive-int */
    private int $maxIpcPayloadBytes = 1_048_576;

    private float $cancellationGraceSeconds = 5.0;

    private float $forceKillSeconds = 2.0;

    /** @var positive-int */
    private int $workerOutputTailBytes = 16_384;

    private ?string $workerAutoloader = null;

    private bool $used = false;

    /** @var RootHooks<TPayload> */
    private readonly RootHooks $hooks;

    /** @var WorkerCrashPolicy<TPayload> */
    private WorkerCrashPolicy $workerCrashPolicy;

    /** @param WorkQueue<TPayload> $queue */
    private function __construct(private readonly WorkQueue $queue)
    {
        $this->hooks = new RootHooks();
        $this->workerCrashPolicy = new AbortWorkerCrashPolicy();
    }

    /**
     * Creates a run around an application-supplied root admission policy.
     * The queue must be finite, non-empty, root-local, and issue every WorkId
     * at most once. Its payload type determines the accepted Strategy type.
     *
     * @template T
     * @param WorkQueue<T> $queue
     * @return self<T>
     */
    public static function fromQueue(WorkQueue $queue): self
    {
        if ($queue->count() < 1) {
            throw InvalidRunRequest::invalidAdmissionQueue('at least one task is required');
        }

        return new self($queue);
    }

    /** Limits simultaneously active OS-level primary workers. Defaults to 1. */
    public function primaryWorkers(int $limit): static
    {
        $this->assertConfigurable();
        $this->primaryWorkers = self::positiveLimit($limit);

        return $this;
    }

    /**
     * Limits reusable logical depth-two workers associated with each primary.
     * Every worker remains a direct OS child owned and reaped by root.
     * Relevant only to Strategies implementing UsesManagedTasks.
     */
    public function childWorkersPerPrimary(int $limit): static
    {
        $this->assertConfigurable();
        $this->childWorkers = self::positiveLimit($limit);

        return $this;
    }

    /** Limits concurrent Fiber tasks inside each primary process. Defaults to 1. */
    public function concurrentTasksPerPrimary(int $limit): static
    {
        $this->assertConfigurable();
        $this->concurrentTasks = self::positiveLimit($limit);

        return $this;
    }

    /**
     * Bounds every serialized control-plane task, event, and result payload.
     * Bulk data should stay process-local instead of crossing IPC.
     */
    public function maxIpcPayloadBytes(int $bytes): static
    {
        $this->assertConfigurable();
        if ($bytes < 1) {
            throw new InvalidRunRequest('Maximum IPC payload must be positive.');
        }

        $this->maxIpcPayloadBytes = $bytes;

        return $this;
    }

    /** @param WorkerCrashPolicy<TPayload> $policy */
    public function workerCrashPolicy(WorkerCrashPolicy $policy): static
    {
        $this->assertConfigurable();
        if ($policy->maxAttempts() < 1) {
            throw new InvalidRunRequest('Worker crash attempt limit must be positive.');
        }

        $this->workerCrashPolicy = $policy;

        return $this;
    }

    /** Time allowed for cooperative cleanup before workers are force-stopped. */
    public function cancellationGrace(float $seconds): static
    {
        $this->assertConfigurable();
        if ($seconds < 0) {
            throw new InvalidRunRequest('Cancellation grace period must not be negative.');
        }

        $this->cancellationGraceSeconds = $seconds;

        return $this;
    }

    /** Time allowed after TERM before an uncooperative worker receives KILL. */
    public function forceKillAfter(float $seconds): static
    {
        $this->assertConfigurable();
        if ($seconds < 0) {
            throw new InvalidRunRequest('Force-kill period must not be negative.');
        }

        $this->forceKillSeconds = $seconds;

        return $this;
    }

    /** Bounds retained stdout/stderr evidence for each worker process. */
    public function workerOutputTailBytes(int $bytes): static
    {
        $this->assertConfigurable();
        if ($bytes < 1) {
            throw new InvalidRunRequest('Worker output tail limit must be positive.');
        }

        $this->workerOutputTailBytes = $bytes;

        return $this;
    }

    /** Overrides automatic Composer autoloader discovery for clean child processes. */
    public function workerAutoloader(string $path): static
    {
        $this->assertConfigurable();
        if ($path === '') {
            throw new InvalidRunRequest('Worker autoloader path must not be empty.');
        }

        $this->workerAutoloader = $path;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\RunContext):mixed $hook */
    public function beforeRun(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->beforeRun[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\WorkerLifecycle):mixed $hook */
    public function onWorkerLifecycle(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->onWorkerLifecycle[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\TaskBoundary<TPayload>):mixed $hook */
    public function beforeTask(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->beforeTask[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Event\EventEnvelope):mixed $hook */
    public function onTaskLifecycle(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->onTaskLifecycle[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Event\EventEnvelope):mixed $hook */
    public function onApplicationEvent(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->onApplicationEvent[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\TaskBoundary<TPayload>):mixed $hook */
    public function afterTask(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->afterTask[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\RunSummary):mixed $hook */
    public function afterRun(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->afterRun[] = $hook;

        return $this;
    }

    /** @param Closure(\Fedor\WorkOrchestrator\Hook\ObserverFailure):mixed $hook */
    public function onObserverFailure(Closure $hook): static
    {
        $this->assertConfigurable();
        $this->hooks->onObserverFailure[] = $hook;

        return $this;
    }

    /**
     * Starts the one-shot run. Operational failures are retained in RunResult;
     * use throwIfFailed() only when exception flow is preferable to ledger
     * inspection. The optional cancellation is propagated through primary and
     * managed child work.
     *
     * @template TResult
     * @param Strategy<TPayload, TResult> $strategy
     * @return RunResult<TResult>
     */
    public function run(Strategy $strategy, ?Cancellation $cancellation = null): RunResult
    {
        $this->assertConfigurable();
        $this->used = true;

        return NativeWorkRunner::run(
            queue: $this->queue,
            strategy: $strategy,
            workerLimit: $this->primaryWorkers,
            configuration: new WorkerRuntimeConfiguration(
                childWorkers: $this->childWorkers,
                concurrentTasks: $this->concurrentTasks,
                maxIpcPayloadBytes: $this->maxIpcPayloadBytes,
                cancellationGraceSeconds: $this->cancellationGraceSeconds,
                forceKillSeconds: $this->forceKillSeconds,
                outputTailBytes: $this->workerOutputTailBytes,
            ),
            hooks: $this->hooks,
            workerCrashPolicy: $this->workerCrashPolicy,
            cancellation: $cancellation,
            autoloadPath: $this->workerAutoloader,
        );
    }

    /** @return positive-int */
    private static function positiveLimit(int $limit): int
    {
        if ($limit < 1) {
            throw InvalidRunRequest::invalidConcurrencyLimit($limit);
        }

        return $limit;
    }

    private function assertConfigurable(): void
    {
        if ($this->used) {
            throw InvalidRunRequest::alreadyUsed();
        }
    }
}
