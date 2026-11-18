<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;

use function Amp\delay;

use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\File\FileEntry;
use Fedor\WorkOrchestrator\WorkEnvelope;
use LogicException;

/** @implements Strategy<FileEntry, string> */
final readonly class FailOnPathStrategy implements Strategy
{
    public function __construct(
        private string $failurePath,
        private string $admissionLog,
        private float $holdSeconds,
    ) {}

    /** @param WorkEnvelope<FileEntry> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        $line = $work->payload->path . "\n";
        if (\file_put_contents($this->admissionLog, $line, \FILE_APPEND | \LOCK_EX) !== \strlen($line)) {
            throw new LogicException('Failed to record file admission.');
        }
        if ($work->payload->path === $this->failurePath) {
            throw new LogicException('Deliberate file Strategy failure.');
        }

        delay($this->holdSeconds, cancellation: $cancellation);

        return $work->payload->path;
    }
}
