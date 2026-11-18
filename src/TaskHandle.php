<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use Amp\Cancellation;
use Amp\Future;
use Fedor\WorkOrchestrator\Exception\ManagedTaskFailed;
use Fedor\WorkOrchestrator\Result\TaskOutcome;

/** Awaitable handle retained by its structured ManagedTaskGroup. */
final readonly class TaskHandle
{
    /** @param Future<TaskOutcome<mixed>> $future */
    public function __construct(
        public string $taskId,
        private Future $future,
    ) {}

    /** @return TaskOutcome<mixed> */
    public function outcome(?Cancellation $cancellation = null): TaskOutcome
    {
        return $this->future->await($cancellation);
    }

    public function value(?Cancellation $cancellation = null): mixed
    {
        $outcome = $this->outcome($cancellation);
        if ($outcome->failure !== null) {
            throw new ManagedTaskFailed($outcome->failure, $outcome->diagnostics);
        }

        return $outcome->value;
    }
}
