<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Result;

use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;

/** Typed execution coordinates for one captured failure. */
final readonly class FailureOrigin
{
    public function __construct(
        public ?ProcessRole $role,
        public ?ExecutorType $executor,
        public int $processId,
        public ?WorkId $workId,
        public ?string $taskId,
        public ?int $attempt,
    ) {
        if ($this->processId < 0) {
            throw new InvalidArgumentException('Failure process identifier must not be negative.');
        }
        if ($this->taskId === '') {
            throw new InvalidArgumentException('Failure task identifier must be null or non-empty.');
        }
        if ($this->attempt !== null && $this->attempt < 1) {
            throw new InvalidArgumentException('Failure attempt must be null or positive.');
        }
    }

    public static function root(): self
    {
        return new self(null, null, self::processId(), null, null, null);
    }

    public static function task(
        EventContext $context,
        ExecutorType $executor,
        int $attempt,
    ): self {
        return new self(
            role: $context->role,
            executor: $executor,
            processId: self::processId(),
            workId: $context->workId,
            taskId: $context->taskId,
            attempt: $attempt,
        );
    }

    /** Origin for a process that disappeared before its PID could be observed. */
    public static function unavailableTask(
        EventContext $context,
        ExecutorType $executor,
        int $attempt,
    ): self {
        return new self(
            role: $context->role,
            executor: $executor,
            processId: 0,
            workId: $context->workId,
            taskId: $context->taskId,
            attempt: $attempt,
        );
    }

    private static function processId(): int
    {
        $processId = \getmypid();

        return $processId === false ? 0 : $processId;
    }
}
