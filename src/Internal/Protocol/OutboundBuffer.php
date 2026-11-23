<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

use RuntimeException;

/** Non-blocking ordered frame writer. @internal */
final class OutboundBuffer
{
    private string $buffer = '';

    public function append(Frame $frame, int $limit): void
    {
        $this->buffer .= FrameCodec::encode($frame, $limit);
    }

    /** @param resource $stream */
    public function flush($stream): void
    {
        while ($this->buffer !== '') {
            $written = \fwrite($stream, $this->buffer);
            if ($written === false) {
                throw new RuntimeException('Protocol frame could not be written.');
            }
            if ($written === 0) {
                return;
            }

            $this->buffer = \substr($this->buffer, $written);
        }
    }

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }
}
