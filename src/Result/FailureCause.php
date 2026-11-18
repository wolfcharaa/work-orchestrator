<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

/** Bounded safe snapshot of one Throwable in a previous-cause chain. */
final readonly class FailureCause
{
    /** @param list<TraceFrame> $frames */
    public function __construct(
        public string $type,
        public string $message,
        public int $code,
        public string $file,
        public int $line,
        public array $frames,
        public int $omittedFrames,
        public bool $messageTruncated,
    ) {}
}
