<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use LogicException;

final readonly class MultiplyHandler implements ManagedTaskHandler
{
    public function __construct(private int $factor) {}

    public function handle(ManagedTask $task, Cancellation $cancellation): int
    {
        if (!$task instanceof MultiplyTask) {
            throw new LogicException('MultiplyHandler received an unsupported task.');
        }

        return $task->value * $this->factor;
    }
}
