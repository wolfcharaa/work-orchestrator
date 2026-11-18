<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Result\TaskOutcome;

/** Serialized terminal response from one primary work item. @internal */
final readonly class PrimaryTaskResult
{
    /** @param TaskOutcome<mixed> $outcome */
    public function __construct(
        public TaskOutcome $outcome,
        public bool $abortRun,
    ) {}
}
