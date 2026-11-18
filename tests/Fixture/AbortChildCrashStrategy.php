<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements UsesManagedTasks<FileEntry, int> */
final class AbortChildCrashStrategy implements UsesManagedTasks
{
    private ?ManagedTaskGroup $tasks = null;

    public function __construct(private readonly string $marker) {}

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $tasks = $this->tasks ?? throw new LogicException('Managed task group was not provided.');
        $value = $tasks->submit(new CrashChildTask($this->marker))->value($cancellation);
        if (!\is_int($value)) {
            throw new LogicException('Managed child task returned an invalid value.');
        }

        return $value;
    }
}
