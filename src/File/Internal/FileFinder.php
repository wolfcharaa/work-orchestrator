<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\File\Internal;

use Fedor\WorkOrchestrator\File\Exception\FileDiscoveryFailed;
use Fedor\WorkOrchestrator\File\Exception\InvalidReadRequest;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/** @internal */
final class FileFinder
{
    /**
     * Recursively discovers regular files. Directory traversal is synchronous and
     * must be completed before latency-sensitive event-loop work starts.
     *
     * @param iterable<array-key, mixed> $inputs Files and directories to inspect.
     * @param list<string>                $patterns Symfony Finder name patterns applied to directories only.
     *
     * @return list<string> Absolute paths sorted by name. Duplicate canonical
     * paths are rejected so every discovered file has one stable WorkId.
     */
    public static function find(iterable $inputs, array $patterns = []): array
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidReadRequest('Discovery patterns must not be empty.');
            }
        }

        /** @var array<string, string> $files */
        $files = [];
        /** @var list<string> $directories */
        $directories = [];
        /** @var array<string, true> $seenDirectories */
        $seenDirectories = [];

        foreach ($inputs as $input) {
            if (!\is_string($input) || $input === '') {
                throw new InvalidReadRequest('Discovery input must be a non-empty path string.');
            }

            $path = \realpath($input);
            if ($path === false) {
                throw FileDiscoveryFailed::forInput($input, 'path does not exist');
            }

            if (\is_file($path)) {
                self::add($files, $path, $input);
                continue;
            }

            if (\is_dir($path)) {
                if (!isset($seenDirectories[$path])) {
                    $directories[] = $path;
                    $seenDirectories[$path] = true;
                }
                continue;
            }

            throw FileDiscoveryFailed::forInput($input, 'path is neither a regular file nor a directory');
        }

        foreach ($directories as $directory) {
            try {
                $finder = Finder::create()
                    ->files()
                    ->in($directory)
                    ->sortByName(useNaturalSort: true);
                if ($patterns !== []) {
                    $finder->name($patterns);
                }

                foreach ($finder as $file) {
                    $path = self::absolutePath($file, $directory);
                    self::add($files, $path, $file->getPathname());
                }
            } catch (FileDiscoveryFailed $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw FileDiscoveryFailed::forInput($directory, $exception->getMessage(), $exception);
            }
        }

        \natcasesort($files);

        return \array_values($files);
    }

    private static function key(string $path): string
    {
        return 'path:' . $path;
    }

    /** @param array<string, string> $files */
    private static function add(array &$files, string $path, string $input): void
    {
        $key = self::key($path);
        if (isset($files[$key])) {
            throw FileDiscoveryFailed::forInput($input, \sprintf(
                'canonical file "%s" is present more than once',
                $path,
            ));
        }

        $files[$key] = $path;
    }

    private static function absolutePath(SplFileInfo $file, string $directory): string
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw FileDiscoveryFailed::forInput($directory, \sprintf(
                'failed to resolve discovered file "%s"',
                $file->getPathname(),
            ));
        }

        return $path;
    }
}
