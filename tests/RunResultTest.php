<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Exception\RunFailed;
use Fedor\WorkOrchestrator\Exception\WorkFailed;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\RunResult;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Result\WorkResult;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class RunResultTest
{
    public function testItKeepsAnEmptyCancelledRunLedger(): void
    {
        $result = self::result([], RunStatus::Cancelled);

        Assert::same($result->results(), []);
        Assert::same($result->status, RunStatus::Cancelled);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsANonListResultArray(): void
    {
        self::result([1 => new WorkResult(new WorkId('one'), TaskOutcome::succeeded(1))]);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsAValueThatIsNotAWorkResult(): void
    {
        self::result(['invalid']);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsDuplicateWorkIds(): void
    {
        self::result([
            new WorkResult(new WorkId('one'), TaskOutcome::succeeded(1)),
            new WorkResult(new WorkId('one'), TaskOutcome::succeeded(2)),
        ]);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testAWorkIdRejectsAnEmptyValue(): void
    {
        new WorkId('');
    }

    #[ExpectException(RunFailed::class)]
    public function testThrowIfFailedIsAnOptInExceptionBoundary(): void
    {
        self::result([], RunStatus::Failed)->throwIfFailed();
    }

    public function testValueThrowsATopLevelWorkFailureWithTheSameDiagnostic(): void
    {
        $diagnostic = FailureDiagnostic::from(new \LogicException('Top-level work failed.'));
        $result = self::result([
            new WorkResult(new WorkId('failed'), TaskOutcome::failed($diagnostic)),
        ], RunStatus::Failed);

        $failure = null;
        try {
            $result->value(new WorkId('failed'));
        } catch (WorkFailed $exception) {
            $failure = $exception;
        }

        Assert::true($failure instanceof WorkFailed);
        Assert::same($failure->diagnostic, $diagnostic);
    }

    /**
     * @param array<array-key, mixed> $results
     * @return RunResult<mixed>
     */
    private static function result(array $results, RunStatus $status = RunStatus::Succeeded): RunResult
    {
        $summaryResults = \array_values(\array_filter(
            $results,
            static fn(mixed $result): bool => $result instanceof WorkResult,
        ));
        $summary = new RunSummary('run-test', $status, $summaryResults, [], 1.0, 2.0);

        return new RunResult('run-test', $status, $results, [], [], $summary);
    }
}
