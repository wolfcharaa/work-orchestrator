<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\ProvidesTaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\TaskExecutionPolicy;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/**
 * @implements UsesManagedTasks<FileEntry, array<string, mixed>>
 * @implements ProvidesTaskExecutionPolicy<FileEntry, array<string, mixed>>
 */
final class ConcurrentTasksStrategy implements
    UsesManagedTasks,
    ProvidesTaskExecutionPolicy,
    TaskExecutionPolicy
{
    private ?ManagedTaskGroup $tasks = null;

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function taskExecutionPolicy(): TaskExecutionPolicy
    {
        return $this;
    }

    public function modeFor(ManagedTask $task): ExecutionMode
    {
        return ExecutionMode::Concurrent;
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{primary: int, children: list<array{process: int, value: int}>}
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $tasks = $this->tasks ?? throw new LogicException('Managed task group was not provided.');
        $handles = [
            $tasks->submit(new ConcurrentValueTask(1)),
            $tasks->submit(new ConcurrentValueTask(2)),
        ];
        $children = [];
        foreach ($handles as $handle) {
            $value = $handle->value($cancellation);
            if (!\is_array($value)
                || !isset($value['process'], $value['value'])
                || !\is_int($value['process'])
                || !\is_int($value['value'])) {
                throw new LogicException('Concurrent task returned an invalid value.');
            }
            $children[] = [
                'process' => $value['process'],
                'value' => $value['value'],
            ];
        }

        $primary = \getmypid();
        if ($primary === false) {
            throw new LogicException('Failed to identify the primary process.');
        }

        return ['primary' => $primary, 'children' => $children];
    }
}
