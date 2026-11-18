<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Closure;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Hook\ObserverFailure;
use Fedor\WorkOrchestrator\Hook\RunContext;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Hook\WorkerLifecycle;

/**
 * Root-local hook registry copied into one run.
 *
 * @template TPayload
 * @internal
 */
final class RootHooks
{
    /** @var list<Closure(RunContext):mixed> */
    public array $beforeRun = [];

    /** @var list<Closure(WorkerLifecycle):mixed> */
    public array $onWorkerLifecycle = [];

    /** @var list<Closure(TaskBoundary<TPayload>):mixed> */
    public array $beforeTask = [];

    /** @var list<Closure(EventEnvelope):mixed> */
    public array $onTaskLifecycle = [];

    /** @var list<Closure(EventEnvelope):mixed> */
    public array $onApplicationEvent = [];

    /** @var list<Closure(TaskBoundary<TPayload>):mixed> */
    public array $afterTask = [];

    /** @var list<Closure(RunSummary):mixed> */
    public array $afterRun = [];

    /** @var list<Closure(ObserverFailure):mixed> */
    public array $onObserverFailure = [];
}
