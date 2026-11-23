<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;

/** @internal */
final class AutoloaderLocator
{
    public static function resolve(?string $override): string
    {
        if ($override !== null) {
            return self::validate($override);
        }

        $composerClass = (new ReflectionClass(ClassLoader::class))->getFileName();
        if ($composerClass === false) {
            throw new RuntimeException('Unable to locate the active Composer class loader.');
        }

        return self::validate(\dirname($composerClass, 2) . '/autoload.php');
    }

    private static function validate(string $path): string
    {
        $resolved = \realpath($path);
        if ($resolved === false || !\is_file($resolved) || !\is_readable($resolved)) {
            throw new RuntimeException(\sprintf('Composer autoloader is not readable: %s.', $path));
        }

        return $resolved;
    }

    private function __construct() {}
}
