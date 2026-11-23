<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

/** Incremental parser enforcing the payload limit before allocation/decode. @internal */
final class FrameParser
{
    private string $buffer = '';

    public function __construct(private readonly int $limit) {}

    /** @return list<Frame> */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];

        while (\strlen($this->buffer) >= FrameCodec::HEADER_BYTES) {
            $header = FrameCodec::header(\substr($this->buffer, 0, FrameCodec::HEADER_BYTES));
            FrameCodec::assertHeader($header['magic'], $header['version'], $header['length'], $this->limit);
            $frameBytes = FrameCodec::HEADER_BYTES + $header['length'];
            if (\strlen($this->buffer) < $frameBytes) {
                break;
            }

            $payload = \substr($this->buffer, FrameCodec::HEADER_BYTES, $header['length']);
            $this->buffer = \substr($this->buffer, $frameBytes);
            $frames[] = FrameCodec::decode($payload);
        }

        return $frames;
    }

    public function hasPartialFrame(): bool
    {
        return $this->buffer !== '';
    }
}
