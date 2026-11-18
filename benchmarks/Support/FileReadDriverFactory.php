<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Driver\EioFilesystemDriver;
use Amp\File\Driver\UvFilesystemDriver;
use Amp\File\Filesystem;
use InvalidArgumentException;
use Revolt\EventLoop;
use RuntimeException;

/** Selects a process-local benchmark reader without adding a public core API. */
final class FileReadDriverFactory
{
    public const ENVIRONMENT_VARIABLE = 'WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER';

    public static function create(FileReadDriver $configured): FileReadDriverSelection
    {
        $requested = self::environmentOverride($configured);
        $eventLoop = EventLoop::getDriver();
        $selected = match ($requested) {
            FileReadDriver::Auto => match (true) {
                UvFilesystemDriver::isSupported($eventLoop) => FileReadDriver::Uv,
                EioFilesystemDriver::isSupported() => FileReadDriver::Eio,
                default => FileReadDriver::Native,
            },
            default => $requested,
        };

        return match ($selected) {
            FileReadDriver::Native => new FileReadDriverSelection(
                $selected,
                'native:fopen/fread',
                null,
            ),
            FileReadDriver::Blocking => new FileReadDriverSelection(
                $selected,
                BlockingFilesystemDriver::class,
                new Filesystem(new BlockingFilesystemDriver()),
            ),
            FileReadDriver::Uv => UvFilesystemDriver::isSupported($eventLoop)
                ? new FileReadDriverSelection(
                    $selected,
                    UvFilesystemDriver::class,
                    new Filesystem(new UvFilesystemDriver($eventLoop)),
                )
                : throw new RuntimeException('The uv file driver requires ext-uv >= 0.3 and Revolt UvDriver.'),
            FileReadDriver::Eio => EioFilesystemDriver::isSupported()
                ? new FileReadDriverSelection(
                    $selected,
                    EioFilesystemDriver::class,
                    new Filesystem(new EioFilesystemDriver($eventLoop)),
                )
                : throw new RuntimeException('The eio file driver requires ext-eio.'),
        };
    }

    private static function environmentOverride(FileReadDriver $configured): FileReadDriver
    {
        $value = \getenv(self::ENVIRONMENT_VARIABLE);
        if ($value === false || $value === '') {
            return $configured;
        }

        return FileReadDriver::tryFrom($value) ?? throw new InvalidArgumentException(\sprintf(
            '%s must be one of: %s.',
            self::ENVIRONMENT_VARIABLE,
            \implode(', ', \array_column(FileReadDriver::cases(), 'value')),
        ));
    }

    private function __construct() {}
}
