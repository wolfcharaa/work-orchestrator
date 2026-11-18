<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark;

use Fedor\WorkOrchestrator\Benchmark\Support\BenchmarkManifest;
use Fedor\WorkOrchestrator\Benchmark\Support\BenchmarkRuntime;
use Fedor\WorkOrchestrator\Example\RecordImport\ImportRecords;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\File\Internal\FileFinder;
use Fedor\WorkOrchestrator\Orchestrator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require \dirname(__DIR__) . '/vendor/autoload.php';

final readonly class RecordPipelineOptions
{
    public function __construct(
        public string $inputDirectory,
        public string $outputDirectory,
        public int $workers,
        public int $writeBatchRows,
        public ?int $expectedRecords,
        public ?int $expectedFiles,
        public ?int $expectedInputBytes,
    ) {}

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        $workers = 4;
        $writeBatchRows = 5_000;
        $expectedRecords = null;
        $expectedFiles = null;
        $expectedInputBytes = null;
        $paths = [];

        foreach (\array_slice($arguments, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                self::printUsage();
                exit(0);
            }

            if (\str_starts_with($argument, '--workers=')) {
                $workers = self::integer($argument, '--workers=', 1);
                continue;
            }

            if (\str_starts_with($argument, '--write-batch-rows=')) {
                $writeBatchRows = self::integer($argument, '--write-batch-rows=', 1);
                continue;
            }

            if (\str_starts_with($argument, '--expected-records=')) {
                $expectedRecords = self::integer($argument, '--expected-records=', 1);
                continue;
            }

            if (\str_starts_with($argument, '--expected-files=')) {
                $expectedFiles = self::integer($argument, '--expected-files=', 1);
                continue;
            }

            if (\str_starts_with($argument, '--expected-input-bytes=')) {
                $expectedInputBytes = self::integer($argument, '--expected-input-bytes=', 1);
                continue;
            }

            if (\str_starts_with($argument, '-')) {
                throw new InvalidArgumentException(\sprintf('Unknown option "%s".', $argument));
            }

            $paths[] = $argument;
        }

        if (\count($paths) !== 2) {
            throw new InvalidArgumentException('Pass input and output directories.');
        }

        return new self(
            $paths[0],
            $paths[1],
            $workers,
            $writeBatchRows,
            $expectedRecords,
            $expectedFiles,
            $expectedInputBytes,
        );
    }

    public static function printUsage(): void
    {
        \fwrite(\STDOUT, <<<'USAGE'
Usage:
  composer benchmark:record-pipeline -- [options] <input-directory> <output-directory>

Options:
  --workers=N           Maximum worker processes and active files (default: 4)
  --write-batch-rows=N  Normalized records per sink write (default: 5000)
  --expected-records=N  Fail unless the pipeline returns exactly N records
  --expected-files=N    Fail unless discovery returns exactly N input files
  --expected-input-bytes=N
                        Fail unless the input manifest contains exactly N bytes
  --help                Show this help

The timed interval excludes input discovery, but includes worker bootstrap,
process-local NDJSON decoding, normalization, output batching, file writes,
finalization, and worker shutdown. The benchmark uses the package's public
consumer API.

USAGE);
    }

    private static function integer(string $argument, string $prefix, int $minimum): int
    {
        $value = \substr($argument, \strlen($prefix));
        $integer = \filter_var($value, \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum],
        ]);

        if (!\is_int($integer)) {
            throw new InvalidArgumentException(\sprintf(
                '%s expects an integer greater than or equal to %d.',
                $prefix,
                $minimum,
            ));
        }

        return $integer;
    }
}

try {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    $options = RecordPipelineOptions::fromArguments($arguments);

    if (!\is_dir($options->inputDirectory)) {
        throw new RuntimeException(\sprintf('Input directory "%s" does not exist.', $options->inputDirectory));
    }

    if (!\is_dir($options->outputDirectory)) {
        throw new RuntimeException(\sprintf('Output directory "%s" does not exist.', $options->outputDirectory));
    }

    $files = FileFinder::find([$options->inputDirectory], patterns: ['*.ndjson']);
    if ($files === []) {
        throw new RuntimeException('No NDJSON files were found in the input directory.');
    }
    $manifest = BenchmarkManifest::fromFiles(
        $files,
        $options->expectedFiles,
        $options->expectedInputBytes,
    );

    $orchestrator = Orchestrator::fromQueue(FileWorkQueue::discover($manifest->files))
        ->primaryWorkers($options->workers);

    \memory_reset_peak_usage();
    $startedAt = \hrtime(true);
    $result = $orchestrator->run(new ImportRecords(
        $options->outputDirectory,
        $options->writeBatchRows,
    ));
    $finishedAt = \hrtime(true);

    $records = 0;
    $writeBatches = 0;
    $inputBytes = 0;
    $outputBytes = 0;
    $outputDigests = [];

    foreach ($result->results() as $fileResult) {
        $path = $fileResult->id->value;
        $value = $fileResult->value();

        $sourceBytes = \filesize($path);
        $normalizedBytes = \filesize($value->outputPath);
        if ($sourceBytes === false || $normalizedBytes === false) {
            throw new RuntimeException(\sprintf('Failed to measure output for "%s".', $path));
        }

        $records += $value->records;
        $writeBatches += $value->writeBatches;
        $inputBytes += $sourceBytes;
        $outputBytes += $normalizedBytes;
        $digest = \hash_file('sha256', $value->outputPath);
        if (!\is_string($digest)) {
            throw new RuntimeException(\sprintf('Failed to hash output for "%s".', $path));
        }
        $outputDigests[] = $digest;
    }

    if ($options->expectedRecords !== null && $records !== $options->expectedRecords) {
        throw new RuntimeException(\sprintf(
            'Expected %d records, pipeline returned %d.',
            $options->expectedRecords,
            $records,
        ));
    }

    if ($inputBytes !== $manifest->bytes) {
        throw new RuntimeException(\sprintf(
            'Expected every manifest byte to be processed: manifest has %d bytes, results cover %d.',
            $manifest->bytes,
            $inputBytes,
        ));
    }

    $duration = ($finishedAt - $startedAt) / 1_000_000_000;
    \sort($outputDigests);

    \fwrite(\STDOUT, \json_encode([
        'status' => 'ok',
        ...BenchmarkRuntime::report(),
        'workers' => $options->workers,
        'write_batch_rows' => $options->writeBatchRows,
        'expected_records' => $options->expectedRecords,
        'expected_files' => $options->expectedFiles,
        'expected_input_bytes' => $options->expectedInputBytes,
        'files' => \count($result->results()),
        'input_bytes' => $inputBytes,
        'records' => $records,
        'write_batches' => $writeBatches,
        'output_bytes' => $outputBytes,
        'output_manifest_sha256' => \hash('sha256', \implode("\n", $outputDigests)),
        'durable_output_sync' => false,
        'duration_seconds' => $duration,
        'records_per_second' => $duration > 0 ? $records / $duration : 0.0,
        'input_mib_per_second' => $duration > 0 ? ($inputBytes / 1024 / 1024) / $duration : 0.0,
        'parent_peak_memory_bytes' => \memory_get_peak_usage(true),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    \fwrite(\STDERR, \json_encode([
        'status' => 'error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
