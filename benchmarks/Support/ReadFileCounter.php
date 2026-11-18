<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

use Amp\Cancellation;
use Amp\File\Filesystem;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Revolt\EventLoop;
use RuntimeException;

final class ReadFileCounter
{
    private const READ_BYTES = 1024 * 1024;

    /**
     * @return array{
     *     bytes: non-negative-int,
     *     read_calls: non-negative-int,
     *     process: positive-int,
     *     event_loop_driver: class-string,
     *     uv_version: string|null,
     *     file_driver: value-of<FileReadDriver>,
     *     reader_backend: non-empty-string
     * }
     * @param WorkEnvelope<FileEntry> $work
     */
    public static function count(
        WorkEnvelope $work,
        Cancellation $cancellation,
        FileReadDriver $driver,
    ): array {
        $selection = FileReadDriverFactory::create($driver);
        [$bytes, $readCalls] = $selection->filesystem === null
            ? self::countNative($work, $cancellation)
            : self::countWithFilesystem($work, $cancellation, $selection->filesystem);

        $uvVersion = \phpversion('uv');
        $process = \getmypid();
        if ($process === false || $process < 1) {
            throw new RuntimeException('Failed to determine the benchmark worker process ID.');
        }

        return [
            'bytes' => $bytes,
            'read_calls' => $readCalls,
            'process' => $process,
            'event_loop_driver' => EventLoop::getDriver()::class,
            'uv_version' => $uvVersion === false ? null : $uvVersion,
            'file_driver' => $selection->driver->value,
            'reader_backend' => $selection->backend,
        ];
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{non-negative-int, non-negative-int}
     */
    private static function countNative(WorkEnvelope $work, Cancellation $cancellation): array
    {
        $bytes = 0;
        $readCalls = 0;
        $path = $work->payload->path;
        $stream = \fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException(\sprintf('Unable to open benchmark input "%s".', $path));
        }

        try {
            while (!\feof($stream)) {
                $cancellation->throwIfRequested();
                $chunk = \fread($stream, self::READ_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException(\sprintf('Unable to read benchmark input "%s".', $path));
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += \strlen($chunk);
                ++$readCalls;
            }
        } finally {
            \fclose($stream);
        }

        return [$bytes, $readCalls];
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return array{non-negative-int, non-negative-int}
     */
    private static function countWithFilesystem(
        WorkEnvelope $work,
        Cancellation $cancellation,
        Filesystem $filesystem,
    ): array {
        $bytes = 0;
        $readCalls = 0;
        $handle = $filesystem->openFile($work->payload->path, 'r');

        try {
            while (($chunk = $handle->read($cancellation, self::READ_BYTES)) !== null) {
                $bytes += \strlen($chunk);
                ++$readCalls;
            }
        } finally {
            $handle->close();
        }

        return [$bytes, $readCalls];
    }

    private function __construct() {}
}
