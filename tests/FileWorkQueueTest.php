<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\AdmissionPriority;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\File\FileOrder;
use Fedor\WorkOrchestrator\File\FilePriority;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\Blocked;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class FileWorkQueueTest
{
    public function testDiscoveryUsesCanonicalPathIdentityAndLargestFirstOrder(): void
    {
        $small = self::temporaryFile('abc');
        $large = self::temporaryFile('1234567');

        try {
            $queue = FileWorkQueue::discover([$small, $large], order: FileOrder::largestFirst());
            $first = $queue->acquire();
            Assert::true($first instanceof Granted);
            /** @var Granted<FileEntry> $first */
            Assert::same($first->lease->work->id->value, \realpath($large));
            Assert::same($first->lease->work->payload->sizeBytes, 7);
        } finally {
            \unlink($small);
            \unlink($large);
        }
    }

    public function testGroupedDiscoveryKeepsIndependentFilesWorkConserving(): void
    {
        $queue = FileWorkQueue::grouped(
            [
                new FileEntry('/data/objects-a.xml', 100),
                new FileEntry('/data/objects-b.xml', 90),
                new FileEntry('/data/dictionary.xml', 2),
                new FileEntry('/data/houses.xml', 80),
            ],
            FilePriority::fromBasenamePatterns(
                [0 => ['objects-*.xml'], 1 => ['houses.xml']],
                AdmissionPriority::opportunistic(),
            ),
            FileOrder::largestFirst(),
        );

        $first = $queue->acquire();
        $second = $queue->acquire();
        $dictionary = $queue->acquire();
        Assert::true($first instanceof Granted);
        Assert::true($second instanceof Granted);
        Assert::true($dictionary instanceof Granted);
        /** @var Granted<FileEntry> $dictionary */
        Assert::same($dictionary->lease->work->payload->path, '/data/dictionary.xml');
        Assert::true($queue->acquire() instanceof Blocked);

        $queue->complete($dictionary->lease, TaskOutcome::succeeded(null));
        $queue->complete($first->lease, TaskOutcome::succeeded(null));
        $queue->complete($second->lease, TaskOutcome::succeeded(null));
        $houses = $queue->acquire();
        Assert::true($houses instanceof Granted);
        /** @var Granted<FileEntry> $houses */
        Assert::same($houses->lease->work->payload->path, '/data/houses.xml');
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-file-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to create a file-work fixture.');
        }

        return $path;
    }
}
