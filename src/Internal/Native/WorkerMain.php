<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Amp\DeferredCancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\RoutesChildApplicationEvents;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameCodec;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use Fedor\WorkOrchestrator\Internal\Protocol\Handshake;
use Fedor\WorkOrchestrator\Internal\Protocol\InitializeWorker;
use Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest;
use Fedor\WorkOrchestrator\Internal\Protocol\WorkerRole;
use Fedor\WorkOrchestrator\Internal\Protocol\WorkRequest;
use Fedor\WorkOrchestrator\Internal\WorkerRuntime;
use Fedor\WorkOrchestrator\ProcessEvents;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use RuntimeException;
use Throwable;

/** Clean child entrypoint for primary and managed worker roles. @internal */
final class WorkerMain
{
    public static function run(WorkerRole $role, string $workerId, int $primaryIndex, int $limit): int
    {
        $connection = new WorkerConnection($limit);
        $events = ProcessEvents::bind($connection, $limit);
        $stop = new DeferredCancellation();
        $cancellation = new ProtocolCancellation($stop, $connection);
        $runtime = null;

        try {
            $processId = \getmypid();
            $connection->send(new Frame(FrameType::Handshake, new Handshake(
                FrameCodec::VERSION,
                $role,
                $workerId,
                $processId === false ? 0 : $processId,
            )));
            $initialize = $connection->receive();
            if ($initialize->type !== FrameType::Initialize || !$initialize->payload instanceof InitializeWorker) {
                throw new RuntimeException('Worker expected one initialize frame after handshake.');
            }
            if ($initialize->payload->role !== $role || $initialize->payload->primaryIndex !== $primaryIndex) {
                throw new RuntimeException('Worker initialization role or identity does not match its process arguments.');
            }

            if ($role === WorkerRole::Primary) {
                $strategy = $initialize->payload->application;
                if (!$strategy instanceof Strategy) {
                    throw new RuntimeException('Primary worker initialization requires a Strategy.');
                }
                $client = new PrimaryProtocolClient(
                    $connection,
                    $stop,
                    $strategy instanceof RoutesChildApplicationEvents ? $strategy : null,
                );
                WorkerRuntime::initialize(
                    $strategy,
                    $initialize->payload->configuration,
                    $client,
                    $cancellation,
                );
                $runtime = WorkerRuntime::class;
            } else {
                $bootstrap = $initialize->payload->application;
                if ($bootstrap !== null && !$bootstrap instanceof ManagedTaskBootstrap) {
                    throw new RuntimeException('Managed worker initialization requires a ManagedTaskBootstrap or null.');
                }
                $managed = new ManagedWorkerRuntime(
                    $bootstrap,
                    $initialize->payload->configuration->maxIpcPayloadBytes,
                );
                $managed->initialize($cancellation);
                $runtime = $managed;
            }

            $connection->send(new Frame(FrameType::Ready));
            while (true) {
                $frame = $connection->receive();
                if ($frame->type === FrameType::Cancel) {
                    $stop->cancel(new RuntimeException(
                        \is_string($frame->payload) ? $frame->payload : 'Root requested cancellation.',
                    ));

                    continue;
                }
                if ($frame->type === FrameType::Finalize) {
                    self::finalize($runtime);
                    $connection->send(new Frame(FrameType::Finalized));

                    return 0;
                }
                if ($role === WorkerRole::Primary) {
                    self::executePrimary($connection, $frame, $cancellation);
                } else {
                    self::executeManaged($connection, $frame, $runtime, $cancellation);
                }
            }
        } catch (Throwable $failure) {
            try {
                $connection->send(new Frame(FrameType::Failure, FailureDiagnostic::from($failure)));
            } catch (Throwable) {
                // The original process failure remains authoritative.
            }

            return 70;
        } finally {
            try {
                self::finalize($runtime);
            } catch (Throwable) {
                // A reported useful/protocol failure remains primary at process exit.
            }
            $cancellation->close();
            $events->close();
            $connection->close();
        }
    }

    private static function executePrimary(
        WorkerConnection $connection,
        Frame $frame,
        ProtocolCancellation $cancellation,
    ): void {
        if ($frame->type !== FrameType::ExecuteWork || !$frame->payload instanceof WorkRequest || $frame->requestId === null) {
            throw new RuntimeException('Primary worker received an invalid execute-work frame.');
        }

        $scope = ProcessEvents::enter($frame->payload->context);
        try {
            $result = WorkerRuntime::execute(
                $frame->payload->work,
                $frame->payload->context,
                $cancellation,
            );
        } finally {
            $scope->close();
        }

        $connection->send(new Frame(FrameType::WorkResult, $result, $frame->requestId));
    }

    private static function executeManaged(
        WorkerConnection $connection,
        Frame $frame,
        object|string|null $runtime,
        ProtocolCancellation $cancellation,
    ): void {
        if (!$runtime instanceof ManagedWorkerRuntime) {
            throw new RuntimeException('Managed worker runtime is not initialized.');
        }
        if ($frame->type !== FrameType::ExecuteManagedTask
            || !$frame->payload instanceof ManagedTaskRequest
            || $frame->requestId === null) {
            throw new RuntimeException('Managed worker received an invalid managed-task frame.');
        }

        $result = $runtime->execute($frame->payload, $cancellation);
        $connection->send(new Frame(FrameType::ManagedTaskResult, $result, $frame->requestId));
    }

    private static function finalize(object|string|null &$runtime): void
    {
        if ($runtime === WorkerRuntime::class) {
            WorkerRuntime::finalize();
            $runtime = null;

            return;
        }
        if ($runtime instanceof ManagedWorkerRuntime) {
            $runtime->finalize();
            $runtime = null;
        }
    }

    private function __construct() {}
}
