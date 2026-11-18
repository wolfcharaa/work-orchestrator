<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\RecordImport;

/** Compact serializable result returned across IPC for one input file. */
final readonly class ImportResult
{
    public function __construct(
        public string $outputPath,
        public int $records,
        public int $writeBatches,
    ) {}
}
