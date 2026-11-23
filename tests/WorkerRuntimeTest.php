<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Amp\Cancellation;
use Amp\NullCancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\Internal\WorkerRuntime;
use Fedor\WorkOrchestrator\Internal\WorkerRuntimeConfiguration;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class WorkerRuntimeTest
{
    public function testItClosesPartiallyBootstrappedStrategyAfterBootFailure(): void
    {
        $lifecycle = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-runtime-');
        if ($lifecycle === false) {
            throw new LogicException('Failed to prepare a worker runtime fixture.');
        }
        $strategy = new class ($lifecycle) implements BootstrapsApplication {
            public function __construct(private readonly string $lifecycle) {}

            public function boot(Cancellation $cancellation): void
            {
                \file_put_contents($this->lifecycle, "boot\n", \FILE_APPEND);
                throw new LogicException('Deliberate boot failure.');
            }

            public function run(WorkEnvelope $work, Cancellation $cancellation): never
            {
                throw new LogicException('This Strategy must not run.');
            }

            public function close(): void
            {
                \file_put_contents($this->lifecycle, "close\n", \FILE_APPEND);
            }
        };
        $failure = null;

        try {
            WorkerRuntime::initialize(
                $strategy,
                new WorkerRuntimeConfiguration(1, 1, 1_048_576),
                null,
                new NullCancellation(),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            WorkerRuntime::finalize();
        }

        try {
            Assert::true($failure instanceof LogicException);
            Assert::same(\file($lifecycle, \FILE_IGNORE_NEW_LINES), ['boot', 'close']);
        } finally {
            \unlink($lifecycle);
        }
    }
}
