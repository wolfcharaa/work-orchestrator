<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests\Fixture;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class FixtureTaskBootstrap implements ManagedTaskBootstrap
{
    public function __construct(
        private int $factor,
        private string $lifecyclePath,
    ) {}

    public function boot(Cancellation $cancellation): ContainerInterface
    {
        self::record($this->lifecyclePath, 'boot');

        return new ManagedTaskContainer($this->factor);
    }

    public function close(): void
    {
        self::record($this->lifecyclePath, 'close');
    }

    private static function record(string $path, string $phase): void
    {
        $process = \getmypid();
        if ($process === false
            || \file_put_contents(
                $path,
                \sprintf('%s:%d', $phase, $process) . "\n",
                \FILE_APPEND | \LOCK_EX,
            ) === false) {
            throw new LogicException('Failed to record managed task bootstrap lifecycle.');
        }
    }
}
