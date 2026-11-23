<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Protocol;

use Fedor\WorkOrchestrator\Exception\IpcPayloadExceeded;
use RuntimeException;
use Throwable;

/** Same-release framed serialization for inherited local pipes. @internal */
final class FrameCodec
{
    public const VERSION = 1;

    public const HEADER_BYTES = 10;

    private const MAGIC = 'WOR1';

    public static function encode(Frame $frame, int $limit): string
    {
        try {
            $payload = \serialize($frame);
        } catch (Throwable $failure) {
            throw IpcPayloadExceeded::unserializable($frame::class, $failure);
        }

        $length = \strlen($payload);
        if ($length < 1 || $length > $limit) {
            throw IpcPayloadExceeded::tooLarge($frame::class, $length, $limit);
        }

        return \pack('a4nN', self::MAGIC, self::VERSION, $length) . $payload;
    }

    public static function decode(string $payload): Frame
    {
        try {
            $frame = \unserialize($payload, ['allowed_classes' => true]);
        } catch (Throwable $failure) {
            throw new RuntimeException('Protocol frame could not be decoded.', previous: $failure);
        }

        if (!$frame instanceof Frame) {
            throw new RuntimeException(\sprintf(
                'Protocol payload must decode to %s, %s decoded.',
                Frame::class,
                \get_debug_type($frame),
            ));
        }

        return $frame;
    }

    /** @return array{magic: string, version: int, length: int} */
    public static function header(string $header): array
    {
        if (\strlen($header) !== self::HEADER_BYTES) {
            throw new RuntimeException('Protocol header is truncated.');
        }

        $decoded = \unpack('a4magic/nversion/Nlength', $header);
        if (!\is_array($decoded)) {
            throw new RuntimeException('Protocol header could not be decoded.');
        }

        $magic = $decoded['magic'] ?? null;
        $version = $decoded['version'] ?? null;
        $length = $decoded['length'] ?? null;
        if (!\is_string($magic) || !\is_int($version) || !\is_int($length)) {
            throw new RuntimeException('Protocol header fields are invalid.');
        }

        return [
            'magic' => $magic,
            'version' => $version,
            'length' => $length,
        ];
    }

    public static function assertHeader(string $magic, int $version, int $length, int $limit): void
    {
        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Protocol frame has invalid magic.');
        }
        if ($version !== self::VERSION) {
            throw new RuntimeException(\sprintf('Unsupported protocol version %d.', $version));
        }
        if ($length < 1 || $length > $limit) {
            throw IpcPayloadExceeded::tooLarge(Frame::class, $length, $limit);
        }
    }

    private function __construct() {}
}
