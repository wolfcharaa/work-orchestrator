<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File\Exception;

use RuntimeException;
use Throwable;

final class FileDiscoveryFailed extends RuntimeException
{
    public static function forInput(string $input, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            \sprintf('Failed to discover files in "%s": %s', $input, $reason),
            previous: $previous,
        );
    }
}
