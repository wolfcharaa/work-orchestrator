<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Internal\ManagedTaskRunner;
use Fedor\WorkOrchestrator\Internal\Protocol\ManagedTaskRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Psr\Container\ContainerInterface;

/** Process-local managed-task handler container and lifecycle. @internal */
final class ManagedWorkerRuntime
{
    private ?ContainerInterface $container = null;

    private bool $bootAttempted = false;

    public function __construct(
        private readonly ?ManagedTaskBootstrap $bootstrap,
        private readonly int $maxPayloadBytes,
    ) {}

    public function initialize(Cancellation $cancellation): void
    {
        if ($this->bootstrap !== null) {
            $this->bootAttempted = true;
            $this->container = $this->bootstrap->boot($cancellation);
        }

        $cancellation->throwIfRequested();
    }

    /** @return TaskOutcome<mixed> */
    public function execute(ManagedTaskRequest $request, Cancellation $cancellation): TaskOutcome
    {
        return ManagedTaskRunner::run(
            $request->task,
            $request->context,
            ExecutorType::Parallel,
            $cancellation,
            $this->container,
            $this->maxPayloadBytes,
        );
    }

    public function finalize(): void
    {
        if (!$this->bootAttempted || $this->bootstrap === null) {
            return;
        }

        try {
            $this->bootstrap->close();
        } finally {
            $this->container = null;
            $this->bootAttempted = false;
        }
    }
}
