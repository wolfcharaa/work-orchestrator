<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File;

use Closure;
use Fedor\WorkOrchestrator\AdmissionPriority;
use InvalidArgumentException;

/**
 * Root-local classifier for strict barrier groups and opportunistic files.
 *
 * fromBasenamePatterns() maps glob patterns to ascending strict priorities.
 * A file matching two different strict priorities is rejected. The unmatched
 * policy can place independent small files in the opportunistic filler lane.
 */
final readonly class FilePriority
{
    /** @param Closure(FileEntry): AdmissionPriority $classifier */
    private function __construct(private Closure $classifier) {}

    /** @param Closure(FileEntry): AdmissionPriority $classifier */
    public static function custom(Closure $classifier): self
    {
        return new self($classifier);
    }

    /**
     * @param array<int, list<string>> $priorities Basename glob patterns keyed
     *        by ascending non-negative strict priority.
     */
    public static function fromBasenamePatterns(
        array $priorities,
        AdmissionPriority $unmatched,
    ): self {
        if ($priorities === []) {
            throw new InvalidArgumentException('At least one basename priority pattern is required.');
        }

        foreach ($priorities as $priority => $patterns) {
            if ($priority < 0 || $patterns === []) {
                throw new InvalidArgumentException('Priority keys must be non-negative and pattern lists non-empty.');
            }

            foreach ($patterns as $pattern) {
                if ($pattern === '') {
                    throw new InvalidArgumentException('Priority patterns must not be empty.');
                }
            }
        }

        return new self(static function (FileEntry $entry) use ($priorities, $unmatched): AdmissionPriority {
            $basename = \basename($entry->path);
            $matchedPriority = null;

            foreach ($priorities as $priority => $patterns) {
                foreach ($patterns as $pattern) {
                    if (!\fnmatch($pattern, $basename)) {
                        continue;
                    }

                    if ($matchedPriority !== null && $matchedPriority !== $priority) {
                        throw new InvalidArgumentException(\sprintf(
                            'File "%s" matches more than one strict priority.',
                            $entry->path,
                        ));
                    }

                    $matchedPriority = $priority;
                }
            }

            return $matchedPriority === null
                ? $unmatched
                : AdmissionPriority::strict($matchedPriority);
        });
    }

    public function for(FileEntry $entry): AdmissionPriority
    {
        return ($this->classifier)($entry);
    }
}
