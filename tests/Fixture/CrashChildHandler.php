<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use LogicException;

final readonly class CrashChildHandler implements ManagedTaskHandler
{
    public function handle(ManagedTask $task, Cancellation $cancellation): int
    {
        if (!$task instanceof CrashChildTask) {
            throw new LogicException('CrashChildHandler received an unsupported task.');
        }

        if (!\is_file($task->marker)) {
            if (\file_put_contents($task->marker, 'crashed', \LOCK_EX) === false) {
                throw new LogicException('Failed to create a child crash marker.');
            }

            exit(23);
        }

        return 42;
    }
}
