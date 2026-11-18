<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

use Amp\File\Filesystem;

/** One process-local benchmark reader selected before a file is opened. */
final readonly class FileReadDriverSelection
{
    /** @param non-empty-string $backend */
    public function __construct(
        public FileReadDriver $driver,
        public string $backend,
        public ?Filesystem $filesystem,
    ) {}
}
