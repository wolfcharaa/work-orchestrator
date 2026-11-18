<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

use InvalidArgumentException;

/**
 * Terminal outcome used by schedulers and structured task groups.
 *
 * @template-covariant TResult
 */
final readonly class TaskOutcome
{
    /** @param list<FailureDiagnostic> $diagnostics */
    private function __construct(
        public TaskStatus $status,
        /** @var TResult */
        public mixed $value,
        public ?FailureDiagnostic $failure,
        public array $diagnostics,
    ) {
        if ($this->status === TaskStatus::Succeeded && $this->failure !== null) {
            throw new InvalidArgumentException('A successful task cannot contain a failure.');
        }

        if ($this->status !== TaskStatus::Succeeded && $this->failure === null) {
            throw new InvalidArgumentException('A failed or cancelled task must contain its cause.');
        }
    }

    /**
     * @template TValue
     * @param TValue $value
     * @param list<FailureDiagnostic> $diagnostics
     * @return self<TValue>
     */
    public static function succeeded(mixed $value, array $diagnostics = []): self
    {
        return new self(TaskStatus::Succeeded, $value, null, $diagnostics);
    }

    /**
     * @param list<FailureDiagnostic> $diagnostics
     * @return self<null>
     */
    public static function failed(FailureDiagnostic $failure, array $diagnostics = []): self
    {
        return new self(TaskStatus::Failed, null, $failure, $diagnostics);
    }

    /**
     * @param list<FailureDiagnostic> $diagnostics
     * @return self<null>
     */
    public static function cancelled(FailureDiagnostic $cause, array $diagnostics = []): self
    {
        return new self(TaskStatus::Cancelled, null, $cause, $diagnostics);
    }

    public function isSuccessful(): bool
    {
        return $this->status === TaskStatus::Succeeded;
    }
}
