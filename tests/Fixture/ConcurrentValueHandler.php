<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;

use function Amp\delay;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use LogicException;

final readonly class ConcurrentValueHandler implements ManagedTaskHandler
{
    /** @return array{process: int, value: int} */
    public function handle(ManagedTask $task, Cancellation $cancellation): array
    {
        if (!$task instanceof ConcurrentValueTask) {
            throw new LogicException('ConcurrentValueHandler received an unsupported task.');
        }

        delay(0.05, cancellation: $cancellation);
        $process = \getmypid();
        if ($process === false) {
            throw new LogicException('Failed to identify a concurrent task process.');
        }

        return ['process' => $process, 'value' => $task->value];
    }
}
