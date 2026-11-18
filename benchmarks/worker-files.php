<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark;

use Fedor\WorkOrchestrator\Benchmark\Support\BenchmarkManifest;
use Fedor\WorkOrchestrator\Benchmark\Support\BenchmarkRuntime;
use Fedor\WorkOrchestrator\Benchmark\Support\FileReadDriver;
use Fedor\WorkOrchestrator\Benchmark\Support\FileReadDriverFactory;
use Fedor\WorkOrchestrator\Benchmark\Support\ReadFileStrategy;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\File\Internal\FileFinder;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\RunResult;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require \dirname(__DIR__) . '/vendor/autoload.php';

final readonly class WorkerBenchmarkOptions
{
    /**
     * @param non-empty-list<string> $inputs
     * @param list<non-empty-string>  $patterns
     */
    public function __construct(
        public FileReadDriver $driver,
        public int $workers,
        public array $inputs,
        public array $patterns,
        public ?int $expectedFiles,
        public ?int $expectedBytes,
    ) {}

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        $driver = FileReadDriver::Auto;
        $workers = 1;
        $inputs = [];
        $patterns = [];
        $expectedFiles = null;
        $expectedBytes = null;

        foreach (\array_slice($arguments, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                self::printUsage();
                exit(0);
            }

            if (\str_starts_with($argument, '--workers=')) {
                $workers = self::integer($argument, '--workers=', minimum: 1);
                continue;
            }

            if (\str_starts_with($argument, '--driver=')) {
                $driver = FileReadDriver::from(\substr($argument, \strlen('--driver=')));
                continue;
            }

            if (\str_starts_with($argument, '--pattern=')) {
                $pattern = \substr($argument, \strlen('--pattern='));
                if ($pattern === '') {
                    throw new InvalidArgumentException('--pattern expects a non-empty Finder glob or regular expression.');
                }
                $patterns[] = $pattern;
                continue;
            }

            if (\str_starts_with($argument, '--expected-files=')) {
                $expectedFiles = self::integer($argument, '--expected-files=', minimum: 1);
                continue;
            }

            if (\str_starts_with($argument, '--expected-bytes=')) {
                $expectedBytes = self::integer($argument, '--expected-bytes=', minimum: 1);
                continue;
            }

            if (\str_starts_with($argument, '-')) {
                throw new InvalidArgumentException(\sprintf('Unknown option "%s".', $argument));
            }

            $inputs[] = $argument;
        }

        if ($inputs === []) {
            throw new InvalidArgumentException('Pass at least one file or directory.');
        }

        return new self(
            $driver,
            $workers,
            $inputs,
            $patterns,
            $expectedFiles,
            $expectedBytes,
        );
    }

    public static function printUsage(): void
    {
        \fwrite(\STDOUT, <<<'USAGE'
Usage:
  composer benchmark:worker-files -- [options] <file-or-directory>...

Options:
  --driver=auto|native|blocking|uv|eio
                           File reader requested in each worker (default: auto)
  --workers=N              Maximum worker processes and active files (default: 1)
  --pattern=GLOB_OR_REGEX  Filter directory discovery; repeatable
  --expected-files=N       Fail unless discovery returns N files
  --expected-bytes=N       Fail unless the manifest contains N bytes
  --help                   Show this help

The measured interval includes worker creation, Strategy initialization, file
processing, finalization, and worker shutdown. Discovery and filesize are
excluded. Parent peak memory does not include child RSS.

WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER overrides --driver. Auto selects uv, then
eio, then native PHP. It does not create nested amphp/file worker processes.

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

/**
 * @param non-empty-list<string> $files
 * @param list<int>              $expectedBytes
 *
 * @return array{
 *     bytes: int,
 *     read_calls: int,
 *     processes: int,
 *     event_loop_drivers: list<class-string>,
 *     uv_versions: list<string|null>,
 *     selected_file_drivers: list<value-of<FileReadDriver>>,
 *     reader_backends: list<non-empty-string>,
 *     duration_seconds: float,
 *     throughput_mib_per_second: float,
 *     parent_peak_memory_bytes: int
 * }
 */
function runWorkerBenchmark(
    WorkerBenchmarkOptions $options,
    array $files,
    array $expectedBytes,
): array {
    $runner = Orchestrator::fromQueue(FileWorkQueue::discover($files))->primaryWorkers($options->workers);

    \memory_reset_peak_usage();
    $startedAt = \hrtime(true);
    $result = $runner->run(new ReadFileStrategy($options->driver));
    $finishedAt = \hrtime(true);

    $totals = verifyResults($result, $files, $expectedBytes);
    $duration = ($finishedAt - $startedAt) / 1_000_000_000;

    return [
        ...$totals,
        'duration_seconds' => $duration,
        'throughput_mib_per_second' => $duration > 0
            ? ($totals['bytes'] / 1024 / 1024) / $duration
            : 0.0,
        'parent_peak_memory_bytes' => \memory_get_peak_usage(true),
    ];
}

/**
 * @param RunResult<array<string, mixed>> $result
 * @param non-empty-list<string> $files
 * @param list<int>              $expectedBytes
 *
 * @return array{
 *     bytes: int,
 *     read_calls: int,
 *     processes: int,
 *     event_loop_drivers: list<class-string>,
 *     uv_versions: list<string|null>,
 *     selected_file_drivers: list<value-of<FileReadDriver>>,
 *     reader_backends: list<non-empty-string>
 * }
 */
function verifyResults(RunResult $result, array $files, array $expectedBytes): array
{
    $bytes = 0;
    $readCalls = 0;
    $processes = [];
    /** @var list<class-string> $eventLoopDrivers */
    $eventLoopDrivers = [];
    $uvVersions = [];
    $selectedFileDrivers = [];
    $readerBackends = [];

    foreach ($files as $index => $path) {
        $value = $result->value(new WorkId($path));
        if (!isset(
            $value['bytes'],
            $value['read_calls'],
            $value['process'],
            $value['event_loop_driver'],
            $value['file_driver'],
            $value['reader_backend'],
        )
            || !\is_int($value['bytes'])
            || !\is_int($value['read_calls'])
            || !\is_int($value['process'])
            || !\is_string($value['file_driver'])
            || FileReadDriver::tryFrom($value['file_driver']) === null
            || !\is_string($value['reader_backend'])
            || $value['reader_backend'] === ''
            || !\array_key_exists('uv_version', $value)
            || ($value['uv_version'] !== null && !\is_string($value['uv_version']))) {
            throw new RuntimeException(\sprintf('Worker returned an invalid result for "%s".', $path));
        }
        $eventLoopDriver = classString($value['event_loop_driver'], $path);
        $fileDriver = FileReadDriver::from($value['file_driver']);

        $expected = $expectedBytes[$index]
            ?? throw new RuntimeException(\sprintf('Expected size is missing for "%s".', $path));
        if ($value['bytes'] !== $expected) {
            throw new RuntimeException(\sprintf(
                'Incomplete read for "%s": expected %d bytes, got %d.',
                $path,
                $expected,
                $value['bytes'],
            ));
        }

        $bytes += $value['bytes'];
        $readCalls += $value['read_calls'];
        $processes[$value['process']] = true;
        if (!\in_array($eventLoopDriver, $eventLoopDrivers, true)) {
            $eventLoopDrivers[] = $eventLoopDriver;
        }
        $uvVersions[$value['uv_version'] ?? 'none'] = $value['uv_version'];
        $selectedFileDrivers[$fileDriver->value] = $fileDriver->value;
        $readerBackends[$value['reader_backend']] = $value['reader_backend'];
    }

    return [
        'bytes' => $bytes,
        'read_calls' => $readCalls,
        'processes' => \count($processes),
        'event_loop_drivers' => $eventLoopDrivers,
        'uv_versions' => \array_values($uvVersions),
        'selected_file_drivers' => \array_values($selectedFileDrivers),
        'reader_backends' => \array_values($readerBackends),
    ];
}

/** @return class-string */
function classString(mixed $value, string $path): string
{
    if (!\is_string($value) || !\class_exists($value)) {
        throw new RuntimeException(\sprintf(
            'Worker returned an invalid event-loop driver for "%s".',
            $path,
        ));
    }

    return $value;
}

try {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    $options = WorkerBenchmarkOptions::fromArguments($arguments);
    $files = FileFinder::find($options->inputs, $options->patterns);
    if ($files === []) {
        throw new RuntimeException('No files were found in the supplied inputs.');
    }

    $manifest = BenchmarkManifest::fromFiles(
        $files,
        $options->expectedFiles,
        $options->expectedBytes,
    );

    $metrics = runWorkerBenchmark($options, $manifest->files, $manifest->bytesByFile);
    $report = [
        'status' => 'ok',
        'mode' => 'strategy',
        'configured_file_driver' => $options->driver->value,
        'file_driver_environment' => (($environment = \getenv(FileReadDriverFactory::ENVIRONMENT_VARIABLE)) === false)
            ? null
            : $environment,
        ...BenchmarkRuntime::report(),
        'workers' => $options->workers,
        'task_read_bytes' => 1024 * 1024,
        'patterns' => $options->patterns,
        'files' => \count($files),
        'manifest_bytes' => $manifest->bytes,
        ...$metrics,
    ];

    \fwrite(\STDOUT, \json_encode(
        $report,
        \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
    ) . "\n");
} catch (Throwable $exception) {
    \fwrite(\STDERR, \json_encode([
        'status' => 'error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
