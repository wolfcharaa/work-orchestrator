<?php

declare(strict_types=1);

use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\RetryScheduled;
use Fedor\WorkOrchestrator\Event\TaskFailed;
use Fedor\WorkOrchestrator\Event\TaskStarted;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomer;
use Fedor\WorkOrchestrator\Example\Observability\CustomerRebuilt;
use Fedor\WorkOrchestrator\Example\Observability\ObservedCustomerStrategy;
use Fedor\WorkOrchestrator\Hook\RunContext;
use Fedor\WorkOrchestrator\Hook\RunSummary;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

require dirname(__DIR__) . '/vendor/autoload.php';

$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
]);

$result = Orchestrator::fromQueue($queue)
    ->beforeRun(static function (RunContext $run): void {
        printf("run: start tasks=%d\n", $run->taskCount);
    })
    ->beforeTask(static function (TaskBoundary $task): void {
        printf("task: admit %s\n", $task->work->id->value);
    })
    ->onTaskLifecycle(static function (EventEnvelope $envelope): void {
        $event = $envelope->event;
        $description = match (true) {
            $event instanceof TaskStarted => "started attempt={$event->attempt}",
            $event instanceof TaskSucceeded => "succeeded attempt={$event->attempt}",
            $event instanceof TaskFailed => "failed attempt={$event->attempt}",
            $event instanceof RetryScheduled => "retry attempt={$event->nextAttempt}",
            default => 'unknown',
        };
        printf("lifecycle: %s %s\n", $envelope->workId->value, $description);
    })
    ->onApplicationEvent(static function (EventEnvelope $envelope): void {
        if ($envelope->event instanceof CustomerRebuilt) {
            printf("application: rebuilt customer=%d\n", $envelope->event->customerId);
        }
    })
    ->afterTask(static function (TaskBoundary $task): void {
        printf(
            "task: complete %s status=%s\n",
            $task->work->id->value,
            $task->outcome?->status->value ?? 'missing',
        );
    })
    ->afterRun(static function (RunSummary $run): void {
        printf("run: finish status=%s\n", $run->status->value);
    })
    ->run(new ObservedCustomerStrategy());

if ($result->status !== \Fedor\WorkOrchestrator\RunStatus::Succeeded) {
    foreach ($result->diagnostics() as $diagnostic) {
        fprintf(STDERR, "%s: %s\n", $diagnostic->type, $diagnostic->message);
    }
    exit(1);
}
