<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

require \dirname(__DIR__) . '/vendor/autoload.php';

/** @return positive-int */
function positiveInteger(string $value, string $name): int
{
    $integer = \filter_var($value, \FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!\is_int($integer)) {
        throw new InvalidArgumentException(\sprintf('%s must be a positive integer.', $name));
    }

    return $integer;
}

try {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];

    if (\count($arguments) < 2 || \count($arguments) > 4) {
        throw new InvalidArgumentException(
            'Usage: php benchmarks/generate-record-fixture.php <output-directory> [files] [records-per-file]',
        );
    }

    $outputDirectory = $arguments[1];
    $files = positiveInteger($arguments[2] ?? '8', 'files');
    $recordsPerFile = positiveInteger($arguments[3] ?? '100000', 'records-per-file');

    if (!\is_dir($outputDirectory) && !\mkdir($outputDirectory, recursive: true)) {
        throw new RuntimeException(\sprintf('Failed to create "%s".', $outputDirectory));
    }

    $bytes = 0;

    for ($file = 1; $file <= $files; ++$file) {
        $path = $outputDirectory . \DIRECTORY_SEPARATOR . \sprintf('records-%03d.ndjson', $file);

        if (\file_exists($path)) {
            throw new RuntimeException(\sprintf('Refusing to overwrite "%s".', $path));
        }

        $stream = \fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException(\sprintf('Failed to create "%s".', $path));
        }

        try {
            for ($record = 1; $record <= $recordsPerFile; ++$record) {
                $id = (($file - 1) * $recordsPerFile) + $record;
                $line = \json_encode([
                    'id' => $id,
                    'name' => \sprintf(' Record %09d ', $id),
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";

                if (\fwrite($stream, $line) !== \strlen($line)) {
                    throw new RuntimeException(\sprintf('Failed to write "%s".', $path));
                }

                $bytes += \strlen($line);
            }
        } finally {
            \fclose($stream);
        }
    }

    \fwrite(\STDOUT, \json_encode([
        'status' => 'ok',
        'directory' => $outputDirectory,
        'files' => $files,
        'records_per_file' => $recordsPerFile,
        'records' => $files * $recordsPerFile,
        'bytes' => $bytes,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    \fwrite(\STDERR, \json_encode([
        'status' => 'error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
