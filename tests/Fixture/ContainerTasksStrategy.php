<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\ProvidesContainer;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ProvidesTaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\TaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * @implements BootstrapsApplication<FileEntry, list<int>>
 * @implements ProvidesContainer<FileEntry, list<int>>
 * @implements ProvidesManagedTaskBootstrap<FileEntry, list<int>>
 * @implements ProvidesTaskExecutionPolicy<FileEntry, list<int>>
 * @implements UsesManagedTasks<FileEntry, list<int>>
 */
final class ContainerTasksStrategy implements
    BootstrapsApplication,
    ProvidesContainer,
    ProvidesManagedTaskBootstrap,
    ProvidesTaskExecutionPolicy,
    TaskExecutionPolicy,
    UsesManagedTasks
{
    private ?ManagedTaskContainer $container = null;

    private ?ManagedTaskGroup $tasks = null;

    public function __construct(
        private readonly ExecutionMode $mode,
        private readonly int $factor,
        private readonly string $childLifecyclePath,
    ) {}

    public function boot(Cancellation $cancellation): void
    {
        $this->container = new ManagedTaskContainer($this->factor);
    }

    public function container(): ContainerInterface
    {
        return $this->container ?? throw new LogicException('Primary container is not bootstrapped.');
    }

    public function managedTaskBootstrap(): ManagedTaskBootstrap
    {
        return new FixtureTaskBootstrap($this->factor, $this->childLifecyclePath);
    }

    public function taskExecutionPolicy(): TaskExecutionPolicy
    {
        return $this;
    }

    public function modeFor(ManagedTask $task): ExecutionMode
    {
        return $this->mode;
    }

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return list<int>
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $tasks = $this->tasks ?? throw new LogicException('Managed task group was not provided.');
        $first = $tasks->submit(new MultiplyTask(3))->value($cancellation);
        $second = $tasks->submit(new MultiplyTask(5))->value($cancellation);
        if (!\is_int($first) || !\is_int($second)) {
            throw new LogicException('Multiply task returned an invalid value.');
        }

        return [$first, $second];
    }

    public function close(): void
    {
        $this->container = null;
        $this->tasks = null;
    }
}
