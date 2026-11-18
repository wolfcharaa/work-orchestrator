<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

use Amp\CancelledException;
use Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded;
use Fedor\WorkOrchestrator\Exception\WorkerBootstrapFailed;
use Fedor\WorkOrchestrator\Exception\WorkerCleanupFailed;
use Throwable;

/** Bounded serializable failure evidence preserved across a process boundary. */
final readonly class FailureDiagnostic
{
    private const MAX_FRAMES = 5;

    private const MAX_CAUSES = 3;

    private const MAX_MESSAGE_BYTES = 8_192;

    /**
     * @param list<TraceFrame> $frames
     * @param list<FailureCause> $causes
     */
    public function __construct(
        public FailureKind $kind,
        public FailureOrigin $origin,
        public string $type,
        public string $message,
        public int $code,
        public string $file,
        public int $line,
        public array $frames,
        public array $causes,
        public int $omittedFrames,
        public int $omittedCauses,
        public bool $messageTruncated,
        public float $occurredAt,
    ) {}

    public static function from(
        Throwable $failure,
        ?FailureOrigin $origin = null,
        ?FailureKind $kind = null,
    ): self {
        [$message, $messageTruncated] = self::message($failure->getMessage());
        [$frames, $omittedFrames] = self::frames($failure);
        [$causes, $omittedCauses] = self::causes($failure);

        return new self(
            kind: $kind ?? self::kind($failure),
            origin: $origin ?? FailureOrigin::root(),
            type: $failure::class,
            message: $message,
            code: (int) $failure->getCode(),
            file: $failure->getFile(),
            line: $failure->getLine(),
            frames: $frames,
            causes: $causes,
            omittedFrames: $omittedFrames,
            omittedCauses: $omittedCauses,
            messageTruncated: $messageTruncated,
            occurredAt: \microtime(true),
        );
    }

    private static function kind(Throwable $failure): FailureKind
    {
        return match (true) {
            $failure instanceof CancelledException => FailureKind::Cancellation,
            $failure instanceof IpcPayloadExceeded => FailureKind::Transport,
            $failure instanceof WorkerBootstrapFailed => FailureKind::Bootstrap,
            $failure instanceof WorkerCleanupFailed => FailureKind::Cleanup,
            default => FailureKind::Application,
        };
    }

    /** @return array{list<TraceFrame>, non-negative-int} */
    private static function frames(Throwable $failure): array
    {
        $trace = $failure->getTrace();
        $frames = [];
        foreach (\array_slice($trace, 0, self::MAX_FRAMES) as $frame) {
            $frames[] = new TraceFrame(
                file: $frame['file'] ?? null,
                line: $frame['line'] ?? null,
                class: $frame['class'] ?? null,
                type: $frame['type'] ?? null,
                function: $frame['function'],
            );
        }

        return [$frames, \max(0, \count($trace) - self::MAX_FRAMES)];
    }

    /** @return array{list<FailureCause>, non-negative-int} */
    private static function causes(Throwable $failure): array
    {
        $causes = [];
        $cause = $failure->getPrevious();
        while ($cause !== null && \count($causes) < self::MAX_CAUSES) {
            [$message, $messageTruncated] = self::message($cause->getMessage());
            [$frames, $omittedFrames] = self::frames($cause);
            $causes[] = new FailureCause(
                type: $cause::class,
                message: $message,
                code: (int) $cause->getCode(),
                file: $cause->getFile(),
                line: $cause->getLine(),
                frames: $frames,
                omittedFrames: $omittedFrames,
                messageTruncated: $messageTruncated,
            );
            $cause = $cause->getPrevious();
        }

        $omitted = 0;
        while ($cause !== null) {
            ++$omitted;
            $cause = $cause->getPrevious();
        }

        return [$causes, $omitted];
    }

    /** @return array{string, bool} */
    private static function message(string $message): array
    {
        if (\strlen($message) <= self::MAX_MESSAGE_BYTES) {
            return [$message, false];
        }

        return [\substr($message, 0, self::MAX_MESSAGE_BYTES), true];
    }
}
