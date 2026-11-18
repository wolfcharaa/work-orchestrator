<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

use Fedor\WorkOrchestrator\Exception\RunFailed;
use Fedor\WorkOrchestrator\Hook\ObserverFailure;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Complete run ledger retained for success, failure, and cancellation.
 *
 * @template-covariant TResult
 */
final readonly class RunResult
{
    /** @var list<WorkResult<TResult>> */
    private array $results;

    /**
     * @param array<array-key, mixed> $results
     * @param list<FailureDiagnostic> $diagnostics
     * @param list<ObserverFailure>   $observerFailures
     */
    public function __construct(
        public string $runId,
        public RunStatus $status,
        array $results,
        private array $diagnostics,
        private array $observerFailures,
        public RunSummary $summary,
    ) {
        if (!\array_is_list($results)) {
            throw new InvalidArgumentException('Work run results must be a list.');
        }

        $ids = [];
        $validated = [];
        foreach ($results as $result) {
            if (!$result instanceof WorkResult) {
                throw new InvalidArgumentException(\sprintf(
                    'Work run result must be a WorkResult, %s given.',
                    \get_debug_type($result),
                ));
            }

            $key = 'work:' . $result->id->value;
            if (isset($ids[$key])) {
                throw new InvalidArgumentException(\sprintf(
                    'Run contains more than one result for work "%s".',
                    $result->id->value,
                ));
            }

            $ids[$key] = true;
            $validated[] = $result;
        }

        $this->results = $validated;
    }

    /** @return list<WorkResult<TResult>> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return list<TResult> Successful values in admission order. */
    public function values(): array
    {
        $values = [];
        foreach ($this->results as $result) {
            if ($result->outcome->isSuccessful()) {
                $values[] = $result->outcome->value;
            }
        }

        return $values;
    }

    /** @return WorkResult<TResult>|null */
    public function result(WorkId $id): ?WorkResult
    {
        foreach ($this->results as $result) {
            if ($result->id->equals($id)) {
                return $result;
            }
        }

        return null;
    }

    /** @return TResult */
    public function value(WorkId $id): mixed
    {
        $result = $this->result($id);
        if ($result === null) {
            throw new OutOfBoundsException(\sprintf('No result found for work "%s".', $id->value));
        }

        return $result->value();
    }

    /** @return list<FailureDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return list<ObserverFailure> */
    public function observerFailures(): array
    {
        return $this->observerFailures;
    }

    /** @return self<TResult> */
    public function throwIfFailed(): self
    {
        if ($this->status !== RunStatus::Succeeded) {
            throw new RunFailed($this);
        }

        return $this;
    }
}
