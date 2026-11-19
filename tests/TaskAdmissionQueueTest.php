<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use function Amp\async;

use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Exhausted;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\Scheduling\GroupedWorkQueue;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\Scheduling\TaskLease;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;
use Throwable;

#[Test]
final class WorkQueueTest
{
    public function testOrderedQueueIsWorkConservingAndPreservesPositions(): void
    {
        $queue = new OrderedWorkQueue(self::work('large.xml', 'small.xml'));
        $first = $queue->acquire();
        $second = $queue->acquire();

        Assert::true($first instanceof Granted);
        Assert::true($second instanceof Granted);
        Assert::same($first->lease->work->payload, 'large.xml');
        Assert::same($first->lease->position, 0);
        Assert::same($second->lease->work->payload, 'small.xml');
        Assert::same($second->lease->position, 1);
        Assert::true($queue->acquire() instanceof Exhausted);

        $queue->complete($second->lease, TaskOutcome::succeeded(2));
        $queue->complete($first->lease, TaskOutcome::succeeded(1));
    }

    public function testGroupedQueueBlocksUntilEveryLeaseInTheOpenGroupCompletes(): void
    {
        $queue = new GroupedWorkQueue([
            self::work('dictionary-a.xml', 'dictionary-b.xml'),
            self::work('objects.xml'),
        ]);
        $first = $queue->acquire();
        $second = $queue->acquire();

        Assert::true($first instanceof Granted);
        Assert::true($second instanceof Granted);
        Assert::true($queue->acquire() instanceof Blocked);

        $queue->complete($first->lease, TaskOutcome::succeeded(null));
        Assert::true($queue->acquire() instanceof Blocked);

        $queue->complete($second->lease, TaskOutcome::succeeded(null));
        $third = $queue->acquire();
        Assert::true($third instanceof Granted);
        Assert::same($third->lease->work->payload, 'objects.xml');
        Assert::same($third->lease->position, 2);

        $queue->complete($third->lease, TaskOutcome::succeeded(null));
        Assert::true($queue->acquire() instanceof Exhausted);
    }

    public function testGroupedQueueKeepsUnadmittedSiblingsInTheOpenGroup(): void
    {
        $queue = new GroupedWorkQueue([
            self::work('dictionary-a.xml', 'dictionary-b.xml'),
            self::work('objects.xml'),
        ]);

        $first = $queue->acquire();
        Assert::true($first instanceof Granted);
        $queue->complete($first->lease, TaskOutcome::succeeded(null));

        $second = $queue->acquire();
        Assert::true($second instanceof Granted);
        Assert::same($second->lease->work->payload, 'dictionary-b.xml');
        $queue->complete($second->lease, TaskOutcome::succeeded(null));

        $third = $queue->acquire();
        Assert::true($third instanceof Granted);
        Assert::same($third->lease->work->payload, 'objects.xml');
    }

    public function testCompletionRejectsACompletedLease(): void
    {
        $queue = new OrderedWorkQueue(self::work('one.xml'));
        $grant = $queue->acquire();
        Assert::true($grant instanceof Granted);
        $queue->complete($grant->lease, TaskOutcome::succeeded(null));

        $failure = null;
        try {
            $queue->complete($grant->lease, TaskOutcome::succeeded(null));
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        Assert::true($failure instanceof InvalidRunRequest);
    }

    #[ExpectException(InvalidRunRequest::class)]
    public function testCompletionRejectsAForgedLeaseWithAKnownIdentifier(): void
    {
        $queue = new OrderedWorkQueue(self::work('one.xml'));
        $grant = $queue->acquire();
        Assert::true($grant instanceof Granted);

        $queue->complete(
            new TaskLease($grant->lease->id, $grant->lease->position, $grant->lease->work),
            TaskOutcome::succeeded(null),
        );
    }

    #[ExpectException(InvalidRunRequest::class)]
    public function testItRejectsDuplicateWorkIdsBeforeAdmission(): void
    {
        new OrderedWorkQueue([
            new WorkEnvelope(new WorkId('duplicate'), 'first'),
            new WorkEnvelope(new WorkId('duplicate'), 'second'),
        ]);
    }

    public function testBlockedDecisionWakesWithoutPollingWhenItsBarrierOpens(): void
    {
        $queue = new GroupedWorkQueue([self::work('a', 'b'), self::work('c')]);
        $first = $queue->acquire();
        $second = $queue->acquire();
        $blocked = $queue->acquire();
        Assert::true($first instanceof Granted);
        Assert::true($second instanceof Granted);
        Assert::true($blocked instanceof Blocked);

        $waiting = async($blocked->await(...));
        Assert::false($waiting->isComplete());
        $queue->complete($first->lease, TaskOutcome::succeeded(null));
        Assert::false($waiting->isComplete());
        $queue->complete($second->lease, TaskOutcome::succeeded(null));
        $waiting->await();
        Assert::true($queue->acquire() instanceof Granted);
    }

    public function testGroupedQueueAdmitsOpportunisticWorkWhileABarrierIsInFlight(): void
    {
        $queue = new GroupedWorkQueue(
            [self::work('large-a', 'large-b'), self::work('dependent')],
            self::work('dictionary'),
        );

        $first = $queue->acquire();
        $second = $queue->acquire();
        $opportunistic = $queue->acquire();
        Assert::true($first instanceof Granted);
        Assert::true($second instanceof Granted);
        Assert::true($opportunistic instanceof Granted);
        Assert::same($opportunistic->lease->work->payload, 'dictionary');

        $queue->complete($opportunistic->lease, TaskOutcome::succeeded(null));
        Assert::true($queue->acquire() instanceof Blocked);
        $queue->complete($first->lease, TaskOutcome::succeeded(null));
        $queue->complete($second->lease, TaskOutcome::succeeded(null));

        $dependent = $queue->acquire();
        Assert::true($dependent instanceof Granted);
        Assert::same($dependent->lease->work->payload, 'dependent');
    }

    /** @return non-empty-list<WorkEnvelope<string>> */
    private static function work(string $first, string ...$rest): array
    {
        $work = [];
        foreach ([$first, ...$rest] as $id) {
            $work[] = new WorkEnvelope(new WorkId($id), $id);
        }

        return $work;
    }
}
