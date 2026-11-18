<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use Fedor\WorkOrchestrator\Exception\InvalidManagedTask;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Throwable;

/** @internal */
final class HandlerResolver
{
    public static function resolve(
        ManagedTask $task,
        ?ContainerInterface $container,
    ): ManagedTaskHandler {
        $class = $task->handler();
        if ($container !== null && $container->has($class)) {
            try {
                $handler = $container->get($class);
            } catch (Throwable $failure) {
                throw new InvalidManagedTask(\sprintf(
                    'Container failed to resolve managed task handler %s.',
                    $class,
                ), previous: $failure);
            }

            if (!$handler instanceof ManagedTaskHandler) {
                throw new InvalidManagedTask(\sprintf(
                    'Container service %s must implement %s.',
                    $class,
                    ManagedTaskHandler::class,
                ));
            }

            return $handler;
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new InvalidManagedTask(\sprintf(
                'Managed task handler %s needs a PSR container or a no-argument constructor.',
                $class,
            ));
        }

        return $reflection->newInstance();
    }

    private function __construct() {}
}
