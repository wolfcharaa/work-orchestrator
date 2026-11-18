<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

use RuntimeException;

/** Verified input identity shared by every benchmark entry point. */
final readonly class BenchmarkManifest
{
    /**
     * @param non-empty-list<string> $files
     * @param non-empty-list<non-negative-int> $bytesByFile
     */
    private function __construct(
        public array $files,
        public array $bytesByFile,
        public int $bytes,
    ) {}

    /**
     * @param non-empty-list<string> $files
     */
    public static function fromFiles(
        array $files,
        ?int $expectedFiles = null,
        ?int $expectedBytes = null,
    ): self {
        if ($expectedFiles !== null && \count($files) !== $expectedFiles) {
            throw new RuntimeException(\sprintf(
                'Expected %d input files, discovered %d.',
                $expectedFiles,
                \count($files),
            ));
        }

        $bytesByFile = [];
        foreach ($files as $path) {
            $size = \filesize($path);
            if (!\is_int($size)) {
                throw new RuntimeException(\sprintf('Failed to determine size of "%s".', $path));
            }

            $bytesByFile[] = $size;
        }

        $bytes = \array_sum($bytesByFile);
        if ($expectedBytes !== null && $bytes !== $expectedBytes) {
            throw new RuntimeException(\sprintf(
                'Expected %d input bytes, discovered %d.',
                $expectedBytes,
                $bytes,
            ));
        }

        /** @var non-empty-list<non-negative-int> $bytesByFile */
        return new self($files, $bytesByFile, $bytes);
    }
}
