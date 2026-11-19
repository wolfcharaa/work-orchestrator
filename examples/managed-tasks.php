<?php

declare(strict_types=1);

use Fedor\WorkOrchestrator\Example\ManagedTasks\NumberBatch;
use Fedor\WorkOrchestrator\Example\ManagedTasks\SumSquaresStrategy;
use Fedor\WorkOrchestrator\ExecutionMode;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

require dirname(__DIR__) . '/vendor/autoload.php';

$numbers = [1, 2, 3, 4];
$result = Orchestrator::fromQueue(new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('batch:demo'), new NumberBatch($numbers)),
]))
    ->childWorkersPerPrimary(2)
    ->run(new SumSquaresStrategy(ExecutionMode::Parallel));

if ($result->status !== \Fedor\WorkOrchestrator\RunStatus::Succeeded) {
    foreach ($result->diagnostics() as $diagnostic) {
        fprintf(STDERR, "%s: %s\n", $diagnostic->type, $diagnostic->message);
    }
    exit(1);
}

printf("numbers => %s\n", implode(', ', $numbers));
printf("sum of squares => %d\n", $result->value(new WorkId('batch:demo')));
printf("run => %s\n", $result->status->value);
