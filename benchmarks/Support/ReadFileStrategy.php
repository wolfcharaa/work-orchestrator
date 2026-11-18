<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;

/** @implements Strategy<FileEntry, array<string, mixed>> */
final readonly class ReadFileStrategy implements Strategy
{
    public function __construct(private FileReadDriver $driver) {}

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
    public function run(WorkEnvelope $work, Cancellation $cancellation): array
    {
        return ReadFileCounter::count($work, $cancellation, $this->driver);
    }
}
