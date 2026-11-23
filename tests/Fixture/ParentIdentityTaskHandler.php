<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use LogicException;

final readonly class ParentIdentityTaskHandler implements ManagedTaskHandler
{
    /** @return array{process: int, parent: int} */
    public function handle(ManagedTask $task, Cancellation $cancellation): array
    {
        if (!$task instanceof ParentIdentityTask) {
            throw new LogicException('ParentIdentityTaskHandler received an unsupported task.');
        }

        $cancellation->throwIfRequested();

        return [
            'process' => ParentIdentityStrategy::processId(),
            'parent' => ParentIdentityStrategy::parentProcessId(),
        ];
    }
}
