<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use function Amp\async;

use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomer;
use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomerStrategy;
use Fedor\WorkOrchestrator\Example\CustomQueue\WindowedWorkQueue;
use Fedor\WorkOrchestrator\Example\ManagedTasks\NumberBatch;
use Fedor\WorkOrchestrator\Example\ManagedTasks\SumSquaresStrategy;
use Fedor\WorkOrchestrator\Example\Observability\CustomerRebuilt;
use Fedor\WorkOrchestrator\Example\Observability\ObservedCustomerStrategy;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class DocumentationExamplesTest
{
    public function testBasicExampleProducesResultsInAdmissionOrder(): void
    {
        $result = Orchestrator::fromQueue(new OrderedWorkQueue([
            new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
            new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
        ]))
            ->primaryWorkers(2)
            ->run(new RebuildCustomerStrategy());

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::same($result->values(), [42, 77]);
    }

    public function testManagedTasksExampleRunsInChildProcesses(): void
    {
        $result = Orchestrator::fromQueue(new OrderedWorkQueue([
            new WorkEnvelope(new WorkId('batch:demo'), new NumberBatch([1, 2, 3, 4])),
        ]))
            ->childWorkersPerPrimary(2)
            ->run(new SumSquaresStrategy(ExecutionMode::Parallel));

        Assert::same($result->value(new WorkId('batch:demo')), 30);
    }

    public function testObservabilityExampleRelaysTheApplicationEventToRoot(): void
    {
        $events = [];
        $result = Orchestrator::fromQueue(new OrderedWorkQueue([
            new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
        ]))
            ->onApplicationEvent(static function (EventEnvelope $envelope) use (&$events): void {
                $events[] = $envelope->event;
            })
            ->run(new ObservedCustomerStrategy());

        Assert::same($result->status, RunStatus::Succeeded);
        Assert::count($events, 1);
        Assert::true(($events[0] ?? null) instanceof CustomerRebuilt);
    }

    public function testCustomQueueExampleReleasesBlockedAdmissionAfterCompletion(): void
    {
        $result = Orchestrator::fromQueue(new WindowedWorkQueue([
            new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
            new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
        ], maxInFlight: 1))
            ->primaryWorkers(2)
            ->run(new RebuildCustomerStrategy());

        Assert::same($result->values(), [42, 77]);
    }

    public function testCustomQueueExampleOwnsReadinessAndExactLeaseValidation(): void
    {
        $queue = new WindowedWorkQueue([
            new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
            new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
        ], maxInFlight: 1);
        $first = $queue->acquire();
        $blocked = $queue->acquire();
        Assert::true($first instanceof Granted);
        Assert::true($blocked instanceof Blocked);

        $forgedFailure = null;
        try {
            $queue->complete(
                new TaskLease($first->lease->id, $first->lease->position, $first->lease->work),
                TaskOutcome::succeeded(42),
            );
        } catch (Throwable $failure) {
            $forgedFailure = $failure;
        }
        Assert::true($forgedFailure instanceof InvalidRunRequest);

        $waiting = async($blocked->await(...));
        Assert::false($waiting->isComplete());
        $queue->complete($first->lease, TaskOutcome::succeeded(42));
        $waiting->await();

        $second = $queue->acquire();
        Assert::true($second instanceof Granted);
        $queue->complete($second->lease, TaskOutcome::succeeded(77));

        $completedFailure = null;
        try {
            $queue->complete($second->lease, TaskOutcome::succeeded(77));
        } catch (Throwable $failure) {
            $completedFailure = $failure;
        }
        Assert::true($completedFailure instanceof InvalidRunRequest);
    }
}
