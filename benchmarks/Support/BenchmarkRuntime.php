<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

/** Captures runtime identity needed to compare benchmark reports safely. */
final class BenchmarkRuntime
{
    /**
     * @return array{
     *     php_version: string,
     *     php_binary: string,
     *     php_sapi: string,
     *     php_memory_limit: string|false,
     *     zend_assertions: string|false,
     *     opcache_enable_cli: string|false,
     *     opcache_jit: string|false,
     *     xdebug_loaded: bool,
     *     xdebug_mode: string|false
     * }
     */
    public static function report(): array
    {
        return [
            'php_version' => \PHP_VERSION,
            'php_binary' => \PHP_BINARY,
            'php_sapi' => \PHP_SAPI,
            'php_memory_limit' => \ini_get('memory_limit'),
            'zend_assertions' => \ini_get('zend.assertions'),
            'opcache_enable_cli' => \ini_get('opcache.enable_cli'),
            'opcache_jit' => \ini_get('opcache.jit'),
            'xdebug_loaded' => \extension_loaded('xdebug'),
            'xdebug_mode' => \ini_get('xdebug.mode'),
        ];
    }

    private function __construct() {}
}
