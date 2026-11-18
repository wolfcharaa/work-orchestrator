<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use Fedor\WorkOrchestrator\ProcessEvents;
use LogicException;

final readonly class NestedValueHandler implements ManagedTaskHandler
{
    /** @return array{process: int, value: int} */
    public function handle(ManagedTask $task, Cancellation $cancellation): array
    {
        if (!$task instanceof NestedValueTask) {
            throw new LogicException('NestedValueHandler received an unsupported task.');
        }

        $cancellation->throwIfRequested();
        \usleep(100_000);
        $cancellation->throwIfRequested();
        $process = \getmypid();
        if ($process === false) {
            throw new LogicException('Failed to identify a managed child process.');
        }

        ProcessEvents::emit(new BytesProgressed($task->value));

        return ['process' => $process, 'value' => $task->value];
    }
}
