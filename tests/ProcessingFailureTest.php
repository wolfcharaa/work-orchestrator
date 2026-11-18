<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Fedor\WorkOrchestrator\Internal\ProcessingFailure;
use LogicException;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ProcessingFailureTest
{
    public function testItPreservesTheFirstFailureAndCancelsSiblingWork(): void
    {
        $state = new ProcessingFailure(null);
        $first = new LogicException('First failure.');

        $state->record($first);
        $state->record(new RuntimeException('Later failure.'));

        Assert::same($state->first(), $first);

        $cancellation = null;
        try {
            $state->cancellation()->throwIfRequested();
        } catch (CancelledException $exception) {
            $cancellation = $exception;
        }

        Assert::true($cancellation instanceof CancelledException);
        Assert::same($cancellation->getPrevious(), $first);
    }

    public function testItComposesExternalCancellationIntoTheRun(): void
    {
        $external = new DeferredCancellation();
        $state = new ProcessingFailure($external->getCancellation());
        $expected = new LogicException('External cancellation.');
        $external->cancel($expected);
        $failure = null;

        try {
            $state->cancellation()->throwIfRequested();
        } catch (CancelledException $exception) {
            $failure = $exception;
        }

        Assert::true($failure instanceof CancelledException);
        Assert::same($failure->getPrevious(), $expected);
        Assert::same($state->first(), null);
    }
}
