<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements UsesManagedTasks<FileEntry, array{primary: array{process: int, parent: int}, managed: array{process: int, parent: int}}> */
final class ParentIdentityStrategy implements UsesManagedTasks
{
    private ?ManagedTaskGroup $tasks = null;

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{primary: array{process: int, parent: int}, managed: array{process: int, parent: int}}
     */
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $tasks = $this->tasks
            ?? throw new LogicException('Expected a task-local managed group.');
        $managed = $tasks->submit(new ParentIdentityTask())->value($cancellation);
        if (!\is_array($managed)
            || !isset($managed['process'], $managed['parent'])
            || !\is_int($managed['process'])
            || !\is_int($managed['parent'])) {
            throw new LogicException('Managed worker returned invalid process identity.');
        }

        return [
            'primary' => [
                'process' => self::processId(),
                'parent' => self::parentProcessId(),
            ],
            'managed' => [
                'process' => $managed['process'],
                'parent' => $managed['parent'],
            ],
        ];
    }

    public static function processId(): int
    {
        $process = \getmypid();
        if ($process === false) {
            throw new LogicException('Failed to identify a worker process.');
        }

        return $process;
    }

    public static function parentProcessId(): int
    {
        if (!\function_exists('posix_getppid')) {
            throw new LogicException('The parent-process topology test requires ext-posix.');
        }

        return \posix_getppid();
    }
}
