<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\Parallel\Worker\Worker;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Hook\WorkerStarted;
use Fedor\WorkOrchestrator\Hook\WorkerStopped;

/**
 * Mutable owner of current primary processes, including fresh replacements.
 *
 * @template TPayload
 * @template TResult
 * @internal
 */
final class PrimaryWorkerRegistry
{
    /** @var non-empty-array<non-negative-int, Worker> */
    private array $workers;

    /**
     * @param non-empty-list<Worker> $workers
     * @param Strategy<TPayload, TResult> $strategy
     * @param HookDispatcher<TPayload> $hooks
     */
    public function __construct(
        array $workers,
        private readonly Strategy $strategy,
        private readonly WorkerRuntimeConfiguration $configuration,
        private readonly HookDispatcher $hooks,
    ) {
        $this->workers = $workers;
    }

    /** @return non-empty-list<non-negative-int> */
    public function indexes(): array
    {
        return \array_keys($this->workers);
    }

    public function worker(int $index): Worker
    {
        return $this->workers[$index]
            ?? throw new \LogicException('Primary worker index is not available.');
    }

    /** @param non-negative-int $index */
    public function replace(int $index, Cancellation $cancellation): Worker
    {
        $previous = $this->worker($index);
        $previous->kill();
        $this->hooks->worker(new WorkerStopped('primary-' . $index, false));

        $workers = WorkerGroupFactory::create(1, $cancellation);
        try {
            WorkerRuntimeInitializer::initialize(
                $workers,
                $this->strategy,
                $this->configuration,
                $cancellation,
            );
        } catch (\Throwable $failure) {
            foreach ($workers as $worker) {
                $worker->kill();
            }

            throw $failure;
        }

        $this->workers[$index] = $worker = $workers[0];
        $this->hooks->worker(new WorkerStarted('primary-' . $index));

        return $worker;
    }

    /** @return list<\Throwable> */
    public function cleanup(): array
    {
        $failures = WorkerGroupCleanup::run(
            \array_values($this->workers),
            $this->configuration->cancellationGraceSeconds,
        );
        $clean = $failures === [];
        foreach ($this->workers as $index => $_worker) {
            $this->hooks->worker(new WorkerStopped('primary-' . $index, $clean));
        }

        return $failures;
    }
}
