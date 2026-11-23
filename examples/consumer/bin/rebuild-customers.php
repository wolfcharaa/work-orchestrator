<?php

declare(strict_types=1);

use App\WorkOrchestratorQuickStart\Work\RebuildCustomer;
use App\WorkOrchestratorQuickStart\Work\RebuildCustomerStrategy;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\RunStatus;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

require dirname(__DIR__) . '/vendor/autoload.php';

$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
]);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());

if ($result->status !== RunStatus::Succeeded) {
    foreach ($result->diagnostics() as $diagnostic) {
        fprintf(STDERR, "%s: %s\n", $diagnostic->type, $diagnostic->message);
        foreach ($diagnostic->causes as $cause) {
            fprintf(STDERR, "caused by %s: %s\n", $cause->type, $cause->message);
        }
    }
    exit(1);
}

foreach ($result->results() as $workResult) {
    printf("%s => %d\n", $workResult->id->value, $workResult->value());
}
printf("run => %s\n", $result->status->value);
