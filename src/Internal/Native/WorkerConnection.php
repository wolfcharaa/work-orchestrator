<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Internal\EventEmitter;
use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameCodec;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameParser;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameType;
use RuntimeException;

/** Blocking worker-side protocol endpoint over inherited fd 3/4. @internal */
final class WorkerConnection implements EventEmitter
{
    /** @var resource */
    private $read;

    /** @var resource */
    private $write;

    private readonly FrameParser $parser;

    /** @var list<Frame> */
    private array $pending = [];

    public function __construct(private readonly int $maxPayloadBytes)
    {
        $read = @\fopen('php://fd/3', 'rb');
        $write = @\fopen('php://fd/4', 'wb');
        if (!\is_resource($read) || !\is_resource($write)) {
            throw new RuntimeException('Worker protocol descriptors 3/4 are unavailable.');
        }

        $this->read = $read;
        $this->write = $write;
        $this->parser = new FrameParser($this->maxPayloadBytes);
    }

    public function send(EventEnvelope|Frame $message): void
    {
        $frame = $message instanceof Frame
            ? $message
            : new Frame(FrameType::Event, $message);
        $bytes = FrameCodec::encode($frame, $this->maxPayloadBytes);
        $offset = 0;
        $length = \strlen($bytes);
        while ($offset < $length) {
            $written = \fwrite($this->write, \substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Worker protocol frame could not be written.');
            }
            $offset += $written;
        }

        \fflush($this->write);
    }

    public function receive(?Cancellation $cancellation = null): Frame
    {
        if ($this->pending !== []) {
            return \array_shift($this->pending);
        }

        while (true) {
            $cancellation?->throwIfRequested();
            $read = [$this->read];
            $write = [];
            $except = null;
            $ready = @\stream_select($read, $write, $except, 0, 50_000);
            if ($ready === false) {
                throw new RuntimeException('Worker protocol wait failed.');
            }
            if ($ready === 0) {
                continue;
            }

            $chunk = \fread($this->read, 65_536);
            if ($chunk === false) {
                throw new RuntimeException('Worker protocol read failed.');
            }
            if ($chunk === '') {
                if (\feof($this->read)) {
                    throw new RuntimeException('Root protocol pipe closed.');
                }

                continue;
            }

            $frames = $this->parser->push($chunk);
            if ($frames === []) {
                continue;
            }

            $first = \array_shift($frames);
            $this->pending = [...$this->pending, ...$frames];

            return $first;
        }
    }

    /** @return list<Frame> */
    public function poll(): array
    {
        $frames = $this->pending;
        $this->pending = [];

        $read = [$this->read];
        $write = [];
        $except = null;
        $ready = @\stream_select($read, $write, $except, 0, 0);
        if ($ready === false) {
            throw new RuntimeException('Worker protocol poll failed.');
        }
        if ($ready === 0) {
            return $frames;
        }

        $chunk = \fread($this->read, 65_536);
        if ($chunk === false) {
            throw new RuntimeException('Worker protocol poll read failed.');
        }
        if ($chunk === '' && \feof($this->read)) {
            throw new RuntimeException('Root protocol pipe closed.');
        }
        if ($chunk !== '') {
            $frames = [...$frames, ...$this->parser->push($chunk)];
        }

        return $frames;
    }

    public function defer(Frame $frame): void
    {
        $this->pending[] = $frame;
    }

    /** @return resource */
    public function protocolRead()
    {
        return $this->read;
    }

    public function close(): void
    {
        @\fclose($this->read);
        @\fclose($this->write);
    }
}
