<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Future;
use Closure;
use Fedor\WorkOrchestrator\Contract\ApplicationEvent;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Hook\ObserverFailure;
use Fedor\WorkOrchestrator\Hook\RunContext;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Hook\WorkerLifecycle;
use LogicException;
use Throwable;

/**
 * Invokes observers without allowing them to control work correctness.
 *
 * @template TPayload
 * @internal
 */
final class HookDispatcher
{
    /** @var list<TrackedHookFuture> */
    private array $pending = [];

    /** @var list<ObserverFailure> */
    private array $failures = [];

    /** @param RootHooks<TPayload> $hooks */
    public function __construct(private readonly RootHooks $hooks) {}

    public function beforeRun(RunContext $context): void
    {
        $this->dispatch($this->hooks->beforeRun, $context, 'beforeRun');
    }

    public function worker(WorkerLifecycle $event): void
    {
        $this->dispatch($this->hooks->onWorkerLifecycle, $event, 'onWorkerLifecycle');
    }

    /** @param TaskBoundary<TPayload> $task */
    public function beforeTask(TaskBoundary $task): void
    {
        $this->dispatch($this->hooks->beforeTask, $task, 'beforeTask');
    }

    public function envelope(EventEnvelope $envelope): void
    {
        if ($envelope->isTaskLifecycle()) {
            $this->dispatch($this->hooks->onTaskLifecycle, $envelope, 'onTaskLifecycle');

            return;
        }

        if ($envelope->event instanceof ApplicationEvent) {
            $this->dispatch($this->hooks->onApplicationEvent, $envelope, 'onApplicationEvent');
        }
    }

    /** @param TaskBoundary<TPayload> $task */
    public function afterTask(TaskBoundary $task): void
    {
        $this->dispatch($this->hooks->afterTask, $task, 'afterTask');
    }

    public function afterRun(RunSummary $summary): void
    {
        $this->dispatch($this->hooks->afterRun, $summary, 'afterRun');
    }

    public function reap(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $tracked) {
            if (!$tracked->future->isComplete()) {
                $this->pending[] = $tracked;

                continue;
            }

            $this->settle($tracked);
        }
    }

    public function awaitAll(): void
    {
        while ($this->pending !== []) {
            $pending = $this->pending;
            $this->pending = [];
            foreach ($pending as $tracked) {
                $this->settle($tracked);
            }
        }
    }

    /** @return list<ObserverFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @template TEvent of object
     *
     * @param list<Closure(TEvent):mixed> $hooks
     * @param TEvent                      $event
     */
    private function dispatch(array $hooks, object $event, string $phase): void
    {
        $this->reap();

        foreach ($hooks as $hook) {
            try {
                $result = $hook($event);
                if ($result === null) {
                    continue;
                }
                if (!$result instanceof Future) {
                    throw new LogicException(\sprintf(
                        '%s hook must return null or Amp\\Future, %s returned.',
                        $phase,
                        \get_debug_type($result),
                    ));
                }

                $this->pending[] = new TrackedHookFuture($result, $phase, true);
            } catch (Throwable $failure) {
                $this->record($phase, $failure, true);
            }
        }
    }

    private function settle(TrackedHookFuture $tracked): void
    {
        try {
            $tracked->future->await();
        } catch (Throwable $failure) {
            $this->record($tracked->phase, $failure, $tracked->notify);
        }
    }

    private function record(string $phase, Throwable $failure, bool $notify): void
    {
        $observerFailure = new ObserverFailure($phase, $failure);
        $this->failures[] = $observerFailure;
        if (!$notify) {
            return;
        }

        foreach ($this->hooks->onObserverFailure as $hook) {
            try {
                $result = $hook($observerFailure);
                if ($result === null) {
                    continue;
                }
                if (!$result instanceof Future) {
                    throw new LogicException(\sprintf(
                        'onObserverFailure hook must return null or Amp\\Future, %s returned.',
                        \get_debug_type($result),
                    ));
                }

                $this->pending[] = new TrackedHookFuture($result, 'onObserverFailure', false);
            } catch (Throwable $observerFailureFailure) {
                $this->record('onObserverFailure', $observerFailureFailure, false);
            }
        }
    }
}
