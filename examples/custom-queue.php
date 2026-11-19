<?php

declare(strict_types=1);

use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomer;
use Fedor\WorkOrchestrator\Example\Basic\RebuildCustomerStrategy;
use Fedor\WorkOrchestrator\Example\CustomQueue\WindowedWorkQueue;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

require dirname(__DIR__) . '/vendor/autoload.php';

$queue = new WindowedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
], maxInFlight: 1);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());

if ($result->status !== \Fedor\WorkOrchestrator\RunStatus::Succeeded) {
    foreach ($result->diagnostics() as $diagnostic) {
        fprintf(STDERR, "%s: %s\n", $diagnostic->type, $diagnostic->message);
    }
    exit(1);
}

foreach ($result->results() as $workResult) {
    printf("%s => %d\n", $workResult->id->value, $workResult->value());
}
printf("run => %s\n", $result->status->value);
