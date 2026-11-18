<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

use Fedor\WorkOrchestrator\Exception\WorkFailed;
use Fedor\WorkOrchestrator\WorkId;

/**
 * Terminal result of one admitted work item, including non-success outcomes.
 *
 * @template-covariant TResult
 */
final readonly class WorkResult
{
    /** @param TaskOutcome<TResult> $outcome */
    public function __construct(
        public WorkId $id,
        public TaskOutcome $outcome,
    ) {}

    /** @return TResult */
    public function value(): mixed
    {
        if ($this->outcome->failure !== null) {
            throw new WorkFailed($this->outcome->failure);
        }

        return $this->outcome->value;
    }
}
