<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

use Fedor\WorkOrchestrator\Contract\ApplicationEvent;
use Fedor\WorkOrchestrator\Event\CoreEvent;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Exception\ProcessEventScopeUnavailable;
use Fedor\WorkOrchestrator\Internal\EventBindingToken;
use Fedor\WorkOrchestrator\Internal\EventChannelBinding;
use Fedor\WorkOrchestrator\Internal\EventEmitter;
use Fedor\WorkOrchestrator\Internal\EventScopeState;
use Fedor\WorkOrchestrator\Internal\EventScopeToken;
use Revolt\EventLoop\FiberLocal;

/**
 * Publishes application events through the active work task channel.
 *
 * The facade deliberately fails outside an active task. Application code may
 * call emit() from a Strategy, handler, or deeper service without retaining a
 * runtime-specific channel. Core lifecycle events remain runtime-only.
 */
final class ProcessEvents
{
    private static ?EventChannelBinding $binding = null;

    /** @var FiberLocal<EventScopeState|null>|null */
    private static ?FiberLocal $scope = null;

    public static function emit(ApplicationEvent $event): void
    {
        self::publish($event);
    }

    /**
     * @internal Runtime API; consumers should call emit() only.
     */
    public static function bind(EventEmitter $emitter, int $maxPayloadBytes): EventBindingToken
    {
        if ($maxPayloadBytes < 1) {
            throw new \InvalidArgumentException('Maximum IPC payload must be positive.');
        }

        if (self::$binding !== null) {
            throw new ProcessEventScopeUnavailable('A process event channel is already bound.');
        }

        self::$binding = $binding = new EventChannelBinding($emitter, $maxPayloadBytes);

        return new EventBindingToken($binding);
    }

    /** @internal */
    public static function enter(EventContext $context): EventScopeToken
    {
        $scope = self::scope();
        $previous = $scope->get();
        $scope->set(new EventScopeState($context));

        return new EventScopeToken($previous);
    }

    /** @internal */
    public static function emitCore(CoreEvent $event): void
    {
        self::publish($event);
    }

    /** Relays an unchanged child envelope through the active primary channel. @internal */
    public static function relay(EventEnvelope $event): void
    {
        $binding = self::$binding
            ?? throw new ProcessEventScopeUnavailable('No process event channel is active.');
        $binding->send($event);
    }

    /** @internal */
    public static function unbind(EventChannelBinding $binding): void
    {
        if (self::$binding !== $binding) {
            throw new ProcessEventScopeUnavailable('Attempted to release a stale process event binding.');
        }

        self::$binding = null;
    }

    /** @internal */
    public static function restoreScope(?EventScopeState $state): void
    {
        if ($state === null) {
            self::scope()->unset();

            return;
        }

        self::scope()->set($state);
    }

    private static function publish(CoreEvent|ApplicationEvent $event): void
    {
        $binding = self::$binding
            ?? throw new ProcessEventScopeUnavailable('No process event channel is active.');
        $scope = self::scope()->get()
            ?? throw new ProcessEventScopeUnavailable('No logical task event scope is active.');
        $context = $scope->context;
        $processId = \getmypid();

        $binding->send(new EventEnvelope(
            runId: $context->runId,
            workId: $context->workId,
            taskId: $context->taskId,
            parentTaskId: $context->parentTaskId,
            role: $context->role,
            depth: $context->depth,
            sourceId: $context->sourceId,
            sequence: $scope->nextSequence(),
            emittedAt: \microtime(true),
            processId: $processId === false ? 0 : $processId,
            event: $event,
        ));
    }

    /** @return FiberLocal<EventScopeState|null> */
    private static function scope(): FiberLocal
    {
        return self::$scope ??= new FiberLocal(static fn(): null => null);
    }

    private function __construct() {}
}
