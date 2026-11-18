<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

/** Safe structural subset of one Throwable trace frame. */
final readonly class TraceFrame
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public ?string $class,
        public ?string $type,
        public string $function,
    ) {}
}
