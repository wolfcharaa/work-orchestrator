<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\RecordImport;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Generator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/** Opens one assigned file and decodes complete NDJSON records inside its worker. */
final readonly class NdjsonRecordParser
{
    public function __construct(private int $maxRecordBytes = 1_048_576)
    {
        if ($this->maxRecordBytes < 1) {
            throw new InvalidArgumentException('Maximum record size must be positive.');
        }
    }

    /**
     * @param WorkEnvelope<FileEntry> $work
     * @return Generator<int, array<string, mixed>>
     */
    public function parse(WorkEnvelope $work, Cancellation $cancellation): Generator
    {
        $path = $work->payload->path;
        $stream = \fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException(\sprintf('Unable to open NDJSON input "%s".', $path));
        }

        try {
            while (($line = \fgets($stream)) !== false) {
                $cancellation->throwIfRequested();
                $line = \rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                yield $this->decode($line);
            }

            if (!\feof($stream)) {
                throw new RuntimeException(\sprintf('Failed to read NDJSON input "%s".', $path));
            }
        } finally {
            \fclose($stream);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $line): array
    {
        $this->assertRecordSize($line);

        try {
            $record = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Input contains invalid NDJSON.', 0, $exception);
        }

        if (!\is_array($record) || \array_is_list($record)) {
            throw new UnexpectedValueException('Each NDJSON line must contain an object.');
        }

        /** @var array<string, mixed> $record */
        return $record;
    }

    private function assertRecordSize(string $bytes): void
    {
        if (\strlen($bytes) > $this->maxRecordBytes) {
            throw new UnexpectedValueException('An NDJSON record exceeds the configured byte limit.');
        }
    }
}
