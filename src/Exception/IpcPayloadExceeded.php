<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use RuntimeException;
use Throwable;

final class IpcPayloadExceeded extends RuntimeException
{
    public static function unserializable(string $type, Throwable $previous): self
    {
        return new self(
            \sprintf('IPC payload of type %s cannot be serialized.', $type),
            previous: $previous,
        );
    }

    public static function tooLarge(string $type, int $bytes, int $limit): self
    {
        return new self(\sprintf(
            'IPC payload of type %s is %d bytes; the configured limit is %d bytes.',
            $type,
            $bytes,
            $limit,
        ));
    }
}
