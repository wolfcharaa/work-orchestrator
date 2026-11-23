<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Fedor\WorkOrchestrator\Internal\Protocol\Frame;
use Fedor\WorkOrchestrator\Internal\Protocol\FrameParser;
use Fedor\WorkOrchestrator\Internal\Protocol\OutboundBuffer;
use Fedor\WorkOrchestrator\Internal\Protocol\WorkerRole;
use RuntimeException;
use Throwable;

/** Root-owned direct child process and its dedicated protocol streams. @internal */
final class ChildProcess
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $protocolWrite;

    /** @var resource */
    private $protocolRead;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private readonly FrameParser $parser;

    private readonly OutboundBuffer $outbound;

    private string $stdoutTail = '';

    private string $stderrTail = '';

    private bool $closed = false;

    private function __construct(
        public readonly WorkerRole $role,
        public readonly string $workerId,
        public readonly int $primaryIndex,
        private readonly int $maxPayloadBytes,
        private readonly int $tailBytes,
    ) {
        $this->parser = new FrameParser($this->maxPayloadBytes);
        $this->outbound = new OutboundBuffer();
    }

    public static function spawn(
        WorkerRole $role,
        string $workerId,
        int $primaryIndex,
        string $autoloadPath,
        int $maxPayloadBytes,
        int $tailBytes,
    ): self {
        if (!\function_exists('proc_open')) {
            throw new RuntimeException('The proc_open function is required by the native runtime.');
        }

        $entrypoint = \dirname(__DIR__, 3) . '/bin/work-orchestrator-worker';
        if (!\is_file($entrypoint) || !\is_readable($entrypoint)) {
            throw new RuntimeException(\sprintf('Worker entrypoint is not readable: %s.', $entrypoint));
        }
        if (!\is_file($autoloadPath) || !\is_readable($autoloadPath)) {
            throw new RuntimeException(\sprintf('Composer autoloader is not readable: %s.', $autoloadPath));
        }

        $command = [
            \PHP_BINARY,
            $entrypoint,
            '--role=' . $role->value,
            '--id=' . $workerId,
            '--primary=' . $primaryIndex,
            '--autoload=' . $autoloadPath,
            '--limit=' . $maxPayloadBytes,
        ];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'r'],
            4 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @\proc_open(
            $command,
            $descriptors,
            $pipes,
            \dirname($autoloadPath),
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            throw new RuntimeException(\sprintf('Unable to start worker %s.', $workerId));
        }

        try {
            $stdout = self::pipe($pipes, 1, $workerId);
            $stderr = self::pipe($pipes, 2, $workerId);
            $protocolWrite = self::pipe($pipes, 3, $workerId);
            $protocolRead = self::pipe($pipes, 4, $workerId);
            foreach ([$stdout, $stderr, $protocolWrite, $protocolRead] as $pipe) {
                if (!\stream_set_blocking($pipe, false)) {
                    throw new RuntimeException(\sprintf('Unable to configure worker %s pipe.', $workerId));
                }
            }
        } catch (Throwable $failure) {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            @\proc_terminate($process, 9);
            @\proc_close($process);

            throw $failure;
        }

        $instance = new self($role, $workerId, $primaryIndex, $maxPayloadBytes, $tailBytes);
        $instance->process = $process;
        $instance->stdout = $stdout;
        $instance->stderr = $stderr;
        $instance->protocolWrite = $protocolWrite;
        $instance->protocolRead = $protocolRead;

        return $instance;
    }

    public function send(Frame $frame): void
    {
        $this->outbound->append($frame, $this->maxPayloadBytes);
    }

    public function hasPendingWrite(): bool
    {
        return !$this->outbound->isEmpty();
    }

    public function flush(): void
    {
        $this->outbound->flush($this->protocolWrite);
    }

    /** @return list<Frame> */
    public function readFrames(): array
    {
        $frames = [];
        foreach ($this->readAvailable($this->protocolRead) as $bytes) {
            $frames = [...$frames, ...$this->parser->push($bytes)];
        }

        return $frames;
    }

    public function drainOutput(): void
    {
        foreach ($this->readAvailable($this->stdout) as $bytes) {
            $this->stdoutTail = $this->tail($this->stdoutTail . $bytes);
        }
        foreach ($this->readAvailable($this->stderr) as $bytes) {
            $this->stderrTail = $this->tail($this->stderrTail . $bytes);
        }
    }

    /** @return resource */
    public function protocolRead()
    {
        return $this->protocolRead;
    }

    /** @return resource */
    public function protocolWrite()
    {
        return $this->protocolWrite;
    }

    /** @return resource */
    public function stdout()
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr()
    {
        return $this->stderr;
    }

    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }

        $status = \proc_get_status($this->process);

        return $status['running'];
    }

    public function processId(): int
    {
        $status = \proc_get_status($this->process);

        return $status['pid'];
    }

    public function terminate(int $signal = 15): void
    {
        if (!$this->closed && $this->isRunning()) {
            @\proc_terminate($this->process, $signal);
        }
    }

    public function stdoutTail(): string
    {
        return $this->stdoutTail;
    }

    public function stderrTail(): string
    {
        return $this->stderrTail;
    }

    public function close(): int
    {
        if ($this->closed) {
            return -1;
        }

        $this->closed = true;
        foreach ([$this->protocolWrite, $this->protocolRead, $this->stdout, $this->stderr] as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        return \proc_close($this->process);
    }

    /**
     * @param resource $stream
     * @return list<string>
     */
    private function readAvailable($stream): array
    {
        $chunks = [];
        while (true) {
            $chunk = \fread($stream, 65_536);
            if ($chunk === false) {
                throw new RuntimeException(\sprintf('Unable to read worker %s stream.', $this->workerId));
            }
            if ($chunk === '') {
                return $chunks;
            }

            $chunks[] = $chunk;
            if (\strlen($chunk) < 65_536) {
                return $chunks;
            }
        }
    }

    private function tail(string $value): string
    {
        if (\strlen($value) <= $this->tailBytes) {
            return $value;
        }

        return \substr($value, -$this->tailBytes);
    }

    /**
     * @param array<int, mixed> $pipes
     * @return resource
     */
    private static function pipe(array $pipes, int $index, string $workerId)
    {
        $pipe = $pipes[$index] ?? null;
        if (!\is_resource($pipe)) {
            throw new RuntimeException(\sprintf('Worker %s pipe %d is unavailable.', $workerId, $index));
        }

        return $pipe;
    }
}
