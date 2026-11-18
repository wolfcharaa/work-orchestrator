<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\RecordImport;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Complete NDJSON parse-normalize-write Strategy used by the public example.
 *
 * @implements BootstrapsApplication<FileEntry, ImportResult>
 */
final class ImportRecords implements BootstrapsApplication
{
    private ?NdjsonRecordParser $parser = null;

    private ?RecordNormalizer $normalizer = null;

    private ?NdjsonRecordSink $sink = null;

    public function __construct(
        private readonly string $outputDirectory,
        private readonly int $writeBatchRows = 1_000,
        private readonly int $maxRecordBytes = 1_048_576,
    ) {
        if ($this->outputDirectory === '') {
            throw new InvalidArgumentException('Output directory must not be empty.');
        }
        if ($this->writeBatchRows < 1) {
            throw new InvalidArgumentException('Write batch size must be positive.');
        }
        if ($this->maxRecordBytes < 1) {
            throw new InvalidArgumentException('Maximum record size must be positive.');
        }
    }

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        if (!\is_dir($this->outputDirectory)) {
            throw new InvalidArgumentException('Output directory must exist before workers start.');
        }

        $this->parser = new NdjsonRecordParser($this->maxRecordBytes);
        $this->normalizer = new RecordNormalizer();
        $this->sink = new NdjsonRecordSink($this->outputDirectory);
    }

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): ImportResult
    {
        $parser = $this->parser ?? throw new LogicException('Record parser is not bootstrapped.');
        $normalizer = $this->normalizer ?? throw new LogicException('Record normalizer is not bootstrapped.');
        $sink = $this->sink ?? throw new LogicException('Record sink is not bootstrapped.');
        $pending = [];
        $recordCount = 0;
        $writeBatches = 0;
        $sink->begin($work->payload->path);

        try {
            foreach ($parser->parse($work, $cancellation) as $record) {
                $pending[] = $normalizer->normalize($record);
                ++$recordCount;

                if (\count($pending) >= $this->writeBatchRows) {
                    $sink->write($work->payload->path, $pending);
                    $pending = [];
                    ++$writeBatches;
                }
            }

            if ($pending !== []) {
                $sink->write($work->payload->path, $pending);
                ++$writeBatches;
            }

            return new ImportResult($sink->commit($work->payload->path), $recordCount, $writeBatches);
        } catch (Throwable $primary) {
            try {
                $sink->rollback($work->payload->path);
            } catch (Throwable $cleanupFailure) {
                throw new RuntimeException(
                    'Record import failed and its partial output could not be removed: '
                    . $cleanupFailure->getMessage(),
                    0,
                    $primary,
                );
            }

            throw $primary;
        }
    }

    public function close(): void
    {
        $sink = $this->sink;
        $this->parser = null;
        $this->normalizer = null;
        $this->sink = null;
        $sink?->close();
    }
}
