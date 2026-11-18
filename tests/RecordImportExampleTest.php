<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Example\RecordImport\ImportRecords;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class RecordImportExampleTest
{
    public function testTheDocumentedBootstrapParsesNormalizesAndWritesRecordsInTheWorker(): void
    {
        $root = \sys_get_temp_dir() . '/work-orchestrator-example-' . \bin2hex(\random_bytes(8));
        $inputDirectory = $root . '/input';
        $outputDirectory = $root . '/output';

        if (!\mkdir($root) || !\mkdir($inputDirectory) || !\mkdir($outputDirectory)) {
            throw new LogicException('Failed to prepare record-import example directories.');
        }

        $input = $inputDirectory . '/records.ndjson';
        $contents = "{\"id\":1,\"name\":\" First \"}\n"
            . "{\"id\":\"2\",\"name\":\"Second\"}\n"
            . "{\"id\":3,\"name\":\" Third\"}";

        if (\file_put_contents($input, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to prepare the record-import example input.');
        }

        $output = null;

        try {
            $result = Orchestrator::fromQueue(FileWorkQueue::discover([$inputDirectory], patterns: ['*.ndjson']))
                ->primaryWorkers(1)
                ->run(new ImportRecords(
                    $outputDirectory,
                    writeBatchRows: 2,
                    maxRecordBytes: 128,
                ));
            $value = $result->value(new WorkId($input));

            $output = $value->outputPath;

            Assert::same($value->records, 3);
            Assert::same($value->writeBatches, 2);
            Assert::same(
                \file_get_contents($output),
                "{\"id\":\"1\",\"name\":\"First\"}\n"
                    . "{\"id\":\"2\",\"name\":\"Second\"}\n"
                    . "{\"id\":\"3\",\"name\":\"Third\"}\n",
            );
        } finally {
            if ($output !== null && \file_exists($output)) {
                \unlink($output);
            }

            \unlink($input);
            \rmdir($outputDirectory);
            \rmdir($inputDirectory);
            \rmdir($root);
        }
    }
}
