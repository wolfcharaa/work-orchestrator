<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded;
use Fedor\WorkOrchestrator\Exception\ProcessEventScopeUnavailable;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Tests\Fixture\BytesProgressed;
use Fedor\WorkOrchestrator\Tests\Fixture\RecordingChannel;
use Fedor\WorkOrchestrator\WorkId;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class ProcessEventsTest
{
    public function testApplicationAndCoreEventsShareCorrelationAndPerSourceSequence(): void
    {
        $channel = new RecordingChannel();
        $binding = ProcessEvents::bind($channel, 16_384);
        $scope = ProcessEvents::enter(new EventContext(
            runId: 'run-1',
            workId: new WorkId('/data/objects.xml'),
            taskId: 'task-1',
            parentTaskId: null,
            role: ProcessRole::Primary,
            depth: 1,
            sourceId: 'primary-1',
        ));

        try {
            ProcessEvents::emitCore(new TaskStarted(ExecutorType::Primary, 1));
            ProcessEvents::emit(new BytesProgressed(1024));
        } finally {
            $scope->close();
            $binding->close();
        }

        Assert::count($channel->sent, 2);
        $first = self::envelope($channel->sent[0] ?? null);
        $second = self::envelope($channel->sent[1] ?? null);
        Assert::same($first->sequence, 1);
        Assert::same($second->sequence, 2);
        Assert::same($second->runId, 'run-1');
        Assert::same($second->workId->value, '/data/objects.xml');
        Assert::same($second->taskId, 'task-1');
        Assert::true($second->event instanceof BytesProgressed);
    }

    public function testEmitFailsOutsideAnActiveTask(): void
    {
        $failure = null;
        try {
            ProcessEvents::emit(new BytesProgressed(1));
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        Assert::true($failure instanceof ProcessEventScopeUnavailable);
    }

    public function testPayloadGuardRejectsAnOversizedEventBeforeChannelSend(): void
    {
        $channel = new RecordingChannel();
        $binding = ProcessEvents::bind($channel, 32);
        $scope = ProcessEvents::enter(new EventContext(
            'run',
            new WorkId('oversized-event'),
            'task',
            null,
            ProcessRole::Primary,
            1,
            'source',
        ));
        $failure = null;

        try {
            ProcessEvents::emit(new BytesProgressed(1));
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $scope->close();
            $binding->close();
        }

        Assert::true($failure instanceof IpcPayloadExceeded);
        Assert::same($channel->sent, []);
    }

    private static function envelope(mixed $value): EventEnvelope
    {
        if (!$value instanceof EventEnvelope) {
            throw new \LogicException('Expected an event envelope.');
        }

        return $value;
    }
}
