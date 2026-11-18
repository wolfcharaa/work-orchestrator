<?php

declare(strict_types=1);

use Fedor\WorkOrchestrator\Example\RecordImport\ImportRecords;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Orchestrator;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 3) {
    fwrite(\STDERR, "Usage: php examples/record-import.php <input-directory> <output-directory>\n");
    exit(2);
}

[, $inputDirectory, $outputDirectory] = $arguments;

$result = Orchestrator::fromQueue(FileWorkQueue::discover([$inputDirectory], patterns: ['*.ndjson']))
    ->primaryWorkers(4)
    ->run(new ImportRecords($outputDirectory, writeBatchRows: 5_000));

foreach ($result->results() as $workResult) {
    $path = $workResult->id->value;
    $value = $workResult->value();
    printf(
        "%s: %d records in %d write batches -> %s\n",
        $path,
        $value->records,
        $value->writeBatches,
        $value->outputPath,
    );
}
