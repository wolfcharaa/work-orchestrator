<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use LogicException;
use Psr\Container\ContainerInterface;

/** Process-local container retained by one managed child worker. @internal */
final class ChildWorkerRuntime
{
    private static bool $initialized = false;

    private static ?ManagedTaskBootstrap $bootstrap = null;

    private static ?ContainerInterface $container = null;

    public static function initialize(
        ?ManagedTaskBootstrap $bootstrap,
        Cancellation $cancellation,
    ): void {
        if (self::$initialized) {
            throw new LogicException('Managed child worker runtime is already initialized.');
        }

        $cancellation->throwIfRequested();
        self::$container = $bootstrap?->boot($cancellation);
        self::$bootstrap = $bootstrap;
        self::$initialized = true;
        $cancellation->throwIfRequested();
    }

    public static function container(): ?ContainerInterface
    {
        if (!self::$initialized) {
            throw new LogicException('Managed child worker runtime is not initialized.');
        }

        return self::$container;
    }

    public static function finalize(): void
    {
        if (!self::$initialized) {
            return;
        }

        $bootstrap = self::$bootstrap;
        self::$container = null;
        self::$bootstrap = null;
        self::$initialized = false;
        $bootstrap?->close();
    }

    private function __construct() {}
}
