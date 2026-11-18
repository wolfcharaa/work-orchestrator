<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final readonly class ManagedTaskContainer implements ContainerInterface
{
    private MultiplyHandler $handler;

    public function __construct(int $factor)
    {
        $this->handler = new MultiplyHandler($factor);
    }

    public function get(string $id): mixed
    {
        if ($id !== MultiplyHandler::class) {
            throw new class (\sprintf('Unknown fixture service %s.', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->handler;
    }

    public function has(string $id): bool
    {
        return $id === MultiplyHandler::class;
    }
}
