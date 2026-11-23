<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

/** One same-release local process protocol message. @internal */
final readonly class Frame
{
    /** @param non-empty-string|null $requestId */
    public function __construct(
        public FrameType $type,
        public mixed $payload = null,
        public ?string $requestId = null,
    ) {}
}
