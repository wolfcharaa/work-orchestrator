<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File;

use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Exception\InvalidRunRequest;
use Fedor\WorkOrchestrator\File\Exception\FileDiscoveryFailed;
use Fedor\WorkOrchestrator\File\Exception\InvalidReadRequest;
use Fedor\WorkOrchestrator\File\Internal\FileFinder;
use Fedor\WorkOrchestrator\Scheduling\GroupedWorkQueue;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

/**
 * Root-side factories for file discovery and admission policies.
 *
 * discover() accepts explicit files or recursively scanned directories. Name
 * patterns use Symfony Finder syntax (for example `*.xml`, `report-??.csv`, or
 * `/^part-\d+\.json$/i`) and apply only to directory contents. Explicit files
 * are always included. Canonical paths become WorkId values; duplicate paths
 * are rejected before workers start.
 *
 *     $queue = FileWorkQueue::discover(
 *         ['/data/incoming'],
 *         patterns: ['*.ndjson'],
 *         order: FileOrder::largestFirst(),
 *     );
 *
 * grouped()/discoverGrouped() add strict priority barriers. Files classified
 * as AdmissionPriority::opportunistic() may fill otherwise idle workers while
 * an open strict group is still in flight, but never open the next barrier.
 */
final class FileWorkQueue
{
    /**
     * @param iterable<array-key, string> $inputs
     * @param list<string>                $patterns
     * @return WorkQueue<FileEntry>
     *
     * @throws InvalidReadRequest
     * @throws FileDiscoveryFailed
     */
    public static function discover(
        iterable $inputs,
        array $patterns = [],
        ?FileOrder $order = null,
    ): WorkQueue {
        return self::ordered(self::discoverEntries($inputs, $patterns), $order);
    }

    /**
     * @param iterable<array-key, string> $inputs
     * @param list<string>                $patterns
     * @return WorkQueue<FileEntry>
     *
     * @throws InvalidReadRequest
     * @throws FileDiscoveryFailed
     */
    public static function discoverGrouped(
        iterable $inputs,
        FilePriority $priority,
        ?FileOrder $withinGroup = null,
        array $patterns = [],
    ): WorkQueue {
        return self::grouped(
            self::discoverEntries($inputs, $patterns),
            $priority,
            $withinGroup,
        );
    }

    /**
     * @param iterable<array-key, FileEntry> $entries
     * @return WorkQueue<FileEntry>
     */
    public static function ordered(iterable $entries, ?FileOrder $order = null): WorkQueue
    {
        $files = ($order ?? FileOrder::pathAscending())->apply(self::entries($entries));

        return new OrderedWorkQueue(self::envelopes($files));
    }

    /**
     * @param iterable<array-key, FileEntry> $entries
     * @return WorkQueue<FileEntry>
     */
    public static function grouped(
        iterable $entries,
        FilePriority $priority,
        ?FileOrder $withinGroup = null,
    ): WorkQueue {
        /** @var array<int, list<FileEntry>> $groups */
        $groups = [];
        /** @var list<FileEntry> $opportunistic */
        $opportunistic = [];

        foreach (self::entries($entries) as $entry) {
            $admission = $priority->for($entry);
            if ($admission->isOpportunistic()) {
                $opportunistic[] = $entry;
                continue;
            }

            $strict = $admission->strict;
            if ($strict === null) {
                throw new \LogicException('Strict file admission has no priority.');
            }

            $groups[$strict][] = $entry;
        }

        $order = $withinGroup ?? FileOrder::pathAscending();
        if ($groups === []) {
            return new OrderedWorkQueue(self::envelopes($order->apply($opportunistic)));
        }

        \ksort($groups, \SORT_NUMERIC);
        /** @var list<non-empty-list<WorkEnvelope<FileEntry>>> $envelopeGroups */
        $envelopeGroups = [];
        foreach ($groups as $group) {
            /** @var non-empty-list<FileEntry> $group */
            $envelopeGroups[] = self::envelopes($order->apply($group));
        }

        return new GroupedWorkQueue(
            $envelopeGroups,
            self::envelopes($order->apply($opportunistic)),
        );
    }

    /**
     * @param iterable<array-key, string> $inputs
     * @param list<string>                $patterns
     * @return non-empty-list<FileEntry>
     */
    private static function discoverEntries(iterable $inputs, array $patterns): array
    {
        $entries = [];
        foreach (FileFinder::find($inputs, $patterns) as $path) {
            if (!\is_readable($path)) {
                throw FileDiscoveryFailed::forInput($path, 'file is not readable');
            }

            $size = \filesize($path);
            if ($size === false) {
                throw FileDiscoveryFailed::forInput($path, 'file size is unavailable');
            }

            $entries[] = new FileEntry($path, $size);
        }

        if ($entries === []) {
            throw InvalidRunRequest::invalidAdmissionQueue('at least one file is required');
        }

        return $entries;
    }

    /**
     * @param iterable<array-key, FileEntry> $entries
     * @return non-empty-list<FileEntry>
     */
    private static function entries(iterable $entries): array
    {
        $files = [];
        foreach ($entries as $entry) {
            $files[] = $entry;
        }

        if ($files === []) {
            throw InvalidRunRequest::invalidAdmissionQueue('at least one file is required');
        }

        return $files;
    }

    /**
     * @param list<FileEntry> $entries
     * @return ($entries is non-empty-list<FileEntry>
     *     ? non-empty-list<WorkEnvelope<FileEntry>>
     *     : list<WorkEnvelope<FileEntry>>)
     */
    private static function envelopes(array $entries): array
    {
        return \array_map(
            static fn(FileEntry $entry): WorkEnvelope => new WorkEnvelope(
                new WorkId($entry->path),
                $entry,
            ),
            $entries,
        );
    }

    private function __construct() {}
}
