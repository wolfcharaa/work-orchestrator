<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded;
use Throwable;

/** @internal */
final class IpcPayloadGuard
{
    public static function assertWithin(mixed $payload, int $limit): void
    {
        try {
            $serialized = \serialize($payload);
        } catch (Throwable $failure) {
            throw IpcPayloadExceeded::unserializable(\get_debug_type($payload), $failure);
        }

        $bytes = \strlen($serialized);
        if ($bytes > $limit) {
            throw IpcPayloadExceeded::tooLarge(\get_debug_type($payload), $bytes, $limit);
        }
    }

    private function __construct() {}
}
