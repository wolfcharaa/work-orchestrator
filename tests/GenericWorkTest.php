<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\Tests\Fixture\NumberPayload;
use Fedor\WorkOrchestrator\Tests\Fixture\SquareNumberStrategy;
use Fedor\WorkOrchestrator\Tests\Fixture\StringLengthStrategy;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GenericWorkTest
{
    public function testTheCoreOrchestratesApplicationPayloadsWithoutFileTypes(): void
    {
        $queue = new OrderedWorkQueue([
            new WorkEnvelope(new WorkId('number:6'), new NumberPayload(6)),
            new WorkEnvelope(new WorkId('number:7'), new NumberPayload(7)),
        ]);

        $result = Orchestrator::fromQueue($queue)
            ->primaryWorkers(2)
            ->run(new SquareNumberStrategy());

        Assert::same($result->values(), [36, 49]);
        Assert::same($result->value(new WorkId('number:7')), 49);
    }

    public function testItRejectsAnOversizedTopLevelRequestBeforeWorkerSubmission(): void
    {
        $id = new WorkId('oversized-request');
        $result = Orchestrator::fromQueue(new OrderedWorkQueue([
            new WorkEnvelope($id, \str_repeat('x', 4_096)),
        ]))
            ->maxIpcPayloadBytes(1_024)
            ->run(new StringLengthStrategy());

        $work = $result->result($id);
        Assert::same($result->status, RunStatus::Failed);
        Assert::true($work !== null);
        Assert::same($work->outcome->failure?->kind, FailureKind::Transport);
    }
}
