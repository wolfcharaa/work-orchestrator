<?php

declare(strict_types=1);

use App\WorkOrchestratorQuickStart\Work\SumSquaresStrategy;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

require dirname(__DIR__) . '/vendor/autoload.php';

$id = new WorkId('numbers');
$result = Orchestrator::fromQueue(new OrderedWorkQueue([
    new WorkEnvelope($id, range(1, 4)),
]))
    ->childWorkersPerPrimary(2)
    ->workerAutoloader(dirname(__DIR__) . '/vendor/autoload.php')
    ->run(new SumSquaresStrategy());

printf("sum => %d\n", $result->value($id));
printf("run => %s\n", $result->status->value);
