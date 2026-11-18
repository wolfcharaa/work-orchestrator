<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Benchmark\Support\BenchmarkManifest;
use LogicException;
use RuntimeException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class BenchmarkManifestTest
{
    public function testItVerifiesTheExpectedFileAndByteTotals(): void
    {
        $first = self::temporaryFile('abc');
        $second = self::temporaryFile('12345');

        try {
            $manifest = BenchmarkManifest::fromFiles(
                [$first, $second],
                expectedFiles: 2,
                expectedBytes: 8,
            );

            Assert::same($manifest->files, [$first, $second]);
            Assert::same($manifest->bytesByFile, [3, 5]);
            Assert::same($manifest->bytes, 8);
        } finally {
            \unlink($first);
            \unlink($second);
        }
    }

    #[ExpectException(RuntimeException::class)]
    public function testItRejectsAnUnexpectedFileCount(): void
    {
        $path = self::temporaryFile('abc');

        try {
            BenchmarkManifest::fromFiles([$path], expectedFiles: 2);
        } finally {
            \unlink($path);
        }
    }

    #[ExpectException(RuntimeException::class)]
    public function testItRejectsAnUnexpectedByteCount(): void
    {
        $path = self::temporaryFile('abc');

        try {
            BenchmarkManifest::fromFiles([$path], expectedBytes: 4);
        } finally {
            \unlink($path);
        }
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-manifest-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to prepare a benchmark manifest fixture.');
        }

        return $path;
    }
}
