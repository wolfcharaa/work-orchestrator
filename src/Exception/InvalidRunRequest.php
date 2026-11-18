<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Exception;

use InvalidArgumentException;

final class InvalidRunRequest extends InvalidArgumentException
{
    public static function alreadyUsed(): self
    {
        return new self('Orchestrator run configuration has already been consumed.');
    }

    public static function invalidAdmissionQueue(string $reason): self
    {
        return new self('Invalid task admission queue: ' . $reason . '.');
    }

    public static function duplicateWorkId(string $id): self
    {
        return new self(\sprintf('Admission queue contains duplicate work identifier "%s".', $id));
    }

    public static function unknownLease(string $id): self
    {
        return new self(\sprintf('Task lease "%s" does not belong to this queue.', $id));
    }

    public static function completedLease(string $id): self
    {
        return new self(\sprintf('Task lease "%s" has already been completed.', $id));
    }

    public static function invalidConcurrencyLimit(int $limit): self
    {
        return new self(\sprintf('Concurrency limit must be positive, %d given.', $limit));
    }
}
