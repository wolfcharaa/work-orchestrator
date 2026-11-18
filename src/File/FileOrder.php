<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File;

use Closure;

/**
 * Root-local deterministic order applied before any worker starts.
 *
 * Use pathAscending(), largestFirst(), smallestFirst(), or custom() with a
 * root-local comparator. Ordering changes admission priority; it
 * does not wait for an earlier item to finish.
 */
final readonly class FileOrder
{
    /** @param Closure(FileEntry, FileEntry): int $comparator */
    private function __construct(private Closure $comparator) {}

    public static function pathAscending(): self
    {
        return new self(static fn(FileEntry $left, FileEntry $right): int =>
            $left->path <=> $right->path);
    }

    public static function largestFirst(): self
    {
        return new self(static fn(FileEntry $left, FileEntry $right): int =>
            (($right->sizeBytes <=> $left->sizeBytes) !== 0
                ? $right->sizeBytes <=> $left->sizeBytes
                : $left->path <=> $right->path));
    }

    public static function smallestFirst(): self
    {
        return new self(static fn(FileEntry $left, FileEntry $right): int =>
            (($left->sizeBytes <=> $right->sizeBytes) !== 0
                ? $left->sizeBytes <=> $right->sizeBytes
                : $left->path <=> $right->path));
    }

    /** @param Closure(FileEntry, FileEntry): int $comparator */
    public static function custom(Closure $comparator): self
    {
        return new self($comparator);
    }

    /**
     * @param list<FileEntry> $entries
     * @return list<FileEntry>
     */
    public function apply(array $entries): array
    {
        \usort($entries, $this->comparator);

        return $entries;
    }
}
