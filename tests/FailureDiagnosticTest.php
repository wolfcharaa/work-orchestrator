<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class FailureDiagnosticTest
{
    public function testItCapturesBoundedStructuredEvidenceWithoutTraceArguments(): void
    {
        $previous = new RuntimeException('cause-4');
        $previous = new RuntimeException('cause-3', previous: $previous);
        $previous = new RuntimeException('cause-2', previous: $previous);
        $previous = new RuntimeException('cause-1', previous: $previous);
        $context = new EventContext(
            runId: 'run-1',
            workId: new WorkId('work-1'),
            taskId: 'task-1',
            parentTaskId: null,
            role: ProcessRole::Primary,
            depth: 1,
            sourceId: 'source-1',
        );

        try {
            self::throwDeep(8, $previous, 'must-not-appear-as-a-trace-argument');
        } catch (Throwable $failure) {
            $diagnostic = FailureDiagnostic::from(
                $failure,
                FailureOrigin::task($context, ExecutorType::Primary, 2),
            );
        }

        Assert::same($diagnostic->kind, FailureKind::Application);
        Assert::same($diagnostic->origin->workId?->value, 'work-1');
        Assert::same($diagnostic->origin->taskId, 'task-1');
        Assert::same($diagnostic->origin->attempt, 2);
        Assert::same($diagnostic->type, LogicException::class);
        Assert::same($diagnostic->message, 'top-level');
        Assert::count($diagnostic->frames, 5);
        Assert::true($diagnostic->omittedFrames > 0);
        Assert::count($diagnostic->causes, 3);
        Assert::same($diagnostic->omittedCauses, 1);
        $firstCause = $diagnostic->causes[0]
            ?? throw new LogicException('Expected the first bounded cause.');
        Assert::same($firstCause->message, 'cause-1');
        Assert::true(!\str_contains(\serialize($diagnostic->frames), 'must-not-appear'));
    }

    public function testItMarksMessageTruncationExplicitly(): void
    {
        $diagnostic = FailureDiagnostic::from(new LogicException(\str_repeat('x', 9_000)));

        Assert::same(\strlen($diagnostic->message), 8_192);
        Assert::true($diagnostic->messageTruncated);
    }

    private static function throwDeep(
        int $remaining,
        Throwable $previous,
        #[\SensitiveParameter]
        string $secret,
    ): never {
        if ($remaining === 0) {
            throw new LogicException('top-level', previous: $previous);
        }

        self::throwDeep($remaining - 1, $previous, $secret);
    }
}
