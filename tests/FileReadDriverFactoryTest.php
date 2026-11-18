<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Benchmark\Support\FileReadDriver;
use Fedor\WorkOrchestrator\Benchmark\Support\FileReadDriverFactory;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class FileReadDriverFactoryTest
{
    public function environmentOverridesTheConfiguredDriver(): void
    {
        $previous = \getenv(FileReadDriverFactory::ENVIRONMENT_VARIABLE);
        \putenv(FileReadDriverFactory::ENVIRONMENT_VARIABLE . '=native');

        try {
            $selection = FileReadDriverFactory::create(FileReadDriver::Uv);

            Assert::same($selection->driver, FileReadDriver::Native);
            Assert::same($selection->backend, 'native:fopen/fread');
            Assert::same($selection->filesystem, null);
        } finally {
            self::restoreEnvironment($previous);
        }
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function invalidEnvironmentOverrideIsRejected(): void
    {
        $previous = \getenv(FileReadDriverFactory::ENVIRONMENT_VARIABLE);
        \putenv(FileReadDriverFactory::ENVIRONMENT_VARIABLE . '=unknown');

        try {
            FileReadDriverFactory::create(FileReadDriver::Auto);
        } finally {
            self::restoreEnvironment($previous);
        }
    }

    private static function restoreEnvironment(string|false $previous): void
    {
        \putenv($previous === false
            ? FileReadDriverFactory::ENVIRONMENT_VARIABLE
            : FileReadDriverFactory::ENVIRONMENT_VARIABLE . '=' . $previous);
    }
}
