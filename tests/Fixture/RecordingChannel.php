<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Closure;

/** @implements Channel<never, mixed> */
final class RecordingChannel implements Channel
{
    /** @var list<mixed> */
    public array $sent = [];

    private bool $closed = false;

    public function receive(?Cancellation $cancellation = null): never
    {
        throw new ChannelException('Recording channel cannot receive.');
    }

    public function send(mixed $data): void
    {
        if ($this->closed) {
            throw new ChannelException('Recording channel is closed.');
        }

        $this->sent[] = $data;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
        }
    }
}
