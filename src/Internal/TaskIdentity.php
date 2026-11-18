<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

/** @internal */
final class TaskIdentity
{
    /** @return non-empty-string */
    public static function create(string $prefix): string
    {
        return $prefix . '-' . \bin2hex(\random_bytes(12));
    }

    private function __construct() {}
}
