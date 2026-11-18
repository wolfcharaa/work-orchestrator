<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\File\Exception\FileDiscoveryFailed;
use Fedor\WorkOrchestrator\File\Exception\InvalidReadRequest;
use Fedor\WorkOrchestrator\File\Internal\FileFinder;
use LogicException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class FileFinderTest
{
    #[ExpectException(FileDiscoveryFailed::class)]
    public function testItRejectsDuplicateCanonicalFiles(): void
    {
        $directory = $this->temporaryDirectory();
        $nested = $directory . '/nested';
        if (!\mkdir($nested)) {
            throw new LogicException('Failed to create a nested test directory.');
        }

        $first = $directory . '/2-records.XML';
        $second = $nested . '/10-records.xml';
        $ignored = $nested . '/notes.txt';
        \file_put_contents($first, '<records/>');
        \file_put_contents($second, '<records/>');
        \file_put_contents($ignored, 'ignored');

        try {
            FileFinder::find([$directory, $first]);
        } finally {
            \unlink($first);
            \unlink($second);
            \unlink($ignored);
            \rmdir($nested);
            \rmdir($directory);
        }
    }

    public function testAnEmptyDirectoryProducesAnEmptyList(): void
    {
        $directory = $this->temporaryDirectory();

        try {
            Assert::same(FileFinder::find([$directory]), []);
        } finally {
            \rmdir($directory);
        }
    }

    #[ExpectException(FileDiscoveryFailed::class)]
    public function testItRejectsAMissingInputPath(): void
    {
        FileFinder::find(['/definitely/missing/fedor-work-orchestrator-directory']);
    }

    public function testItAppliesOptionalPatternsOnlyToDirectoryDiscovery(): void
    {
        $directory = $this->temporaryDirectory();
        $xml = $directory . '/records.XML';
        $json = $directory . '/records.json';
        \file_put_contents($xml, '<records/>');
        \file_put_contents($json, '{}');

        try {
            $files = FileFinder::find([$directory, $json], ['/\.xml$/i']);

            Assert::same($files, [$json, $xml]);
        } finally {
            \unlink($xml);
            \unlink($json);
            \rmdir($directory);
        }
    }

    #[ExpectException(InvalidReadRequest::class)]
    public function testItRejectsAnEmptyDiscoveryPattern(): void
    {
        FileFinder::find([], ['']);
    }

    private function temporaryDirectory(): string
    {
        $directory = \sys_get_temp_dir() . '/work-orchestrator-' . \bin2hex(\random_bytes(8));
        if (!\mkdir($directory)) {
            throw new LogicException('Failed to create a temporary test directory.');
        }

        return $directory;
    }
}
