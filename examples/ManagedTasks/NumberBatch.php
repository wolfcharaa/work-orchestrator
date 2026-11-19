<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\ManagedTasks;

final readonly class NumberBatch
{
    /** @param non-empty-list<int> $numbers */
    public function __construct(public array $numbers) {}
}
