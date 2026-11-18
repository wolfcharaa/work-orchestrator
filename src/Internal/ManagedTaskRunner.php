<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\TaskFailed;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Psr\Container\ContainerInterface;
use Throwable;

/** Executes one managed message with identical lifecycle semantics. @internal */
final class ManagedTaskRunner
{
    /** @return TaskOutcome<mixed> */
    public static function run(
        ManagedTask $task,
        EventContext $context,
        ExecutorType $executor,
        Cancellation $cancellation,
        ?ContainerInterface $container,
        int $maxPayloadBytes,
    ): TaskOutcome {
        $scope = ProcessEvents::enter($context);

        try {
            ProcessEvents::emitCore(new TaskStarted($executor, 1));
            $cancellation->throwIfRequested();
            $value = HandlerResolver::resolve($task, $container)->handle($task, $cancellation);
            $cancellation->throwIfRequested();
            IpcPayloadGuard::assertWithin($value, $maxPayloadBytes);
            ProcessEvents::emitCore(new TaskSucceeded($executor, 1));

            return TaskOutcome::succeeded($value);
        } catch (CancelledException $failure) {
            $diagnostic = FailureDiagnostic::from(
                $failure,
                FailureOrigin::task($context, $executor, 1),
            );
            self::emitFailure($executor, $failure);

            return TaskOutcome::cancelled($diagnostic);
        } catch (Throwable $failure) {
            $diagnostic = FailureDiagnostic::from(
                $failure,
                FailureOrigin::task($context, $executor, 1),
            );
            self::emitFailure($executor, $failure);

            return TaskOutcome::failed($diagnostic);
        } finally {
            $scope->close();
        }
    }

    private static function emitFailure(ExecutorType $executor, Throwable $failure): void
    {
        ProcessEvents::emitCore(new TaskFailed(
            executor: $executor,
            attempt: 1,
            failureType: $failure::class,
            message: $failure->getMessage(),
        ));
    }

    private function __construct() {}
}
