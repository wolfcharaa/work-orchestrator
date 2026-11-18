<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File;

use InvalidArgumentException;

/** Immutable serializable file payload produced by root-side preflight. */
final readonly class FileEntry
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
    ) {
        if ($this->path === '') {
            throw new InvalidArgumentException('File path must not be empty.');
        }

        if ($this->sizeBytes < 0) {
            throw new InvalidArgumentException('File size must not be negative.');
        }
    }
}
