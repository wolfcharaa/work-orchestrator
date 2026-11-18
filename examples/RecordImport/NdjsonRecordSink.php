<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\RecordImport;

use LogicException;
use RuntimeException;

/**
 * Example bounded sink that writes normalized records to an output file.
 *
 * A production application can replace this class with a database/COPY sink;
 * the Handler and process-local ownership model remain the same.
 */
final class NdjsonRecordSink
{
    /** @var array<string, array{file: resource, temporary: string, final: string}> */
    private array $open = [];

    public function __construct(private readonly string $outputDirectory) {}

    public function begin(string $sourcePath): void
    {
        if (isset($this->open[$sourcePath])) {
            throw new LogicException('A sink session is already open for this source file.');
        }

        $base = \pathinfo($sourcePath, \PATHINFO_FILENAME);
        $safeBase = \preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        if (!\is_string($safeBase) || $safeBase === '') {
            $safeBase = 'records';
        }

        $final = $this->outputDirectory
            . \DIRECTORY_SEPARATOR
            . $safeBase
            . '-'
            . \substr(\hash('sha256', $sourcePath), 0, 12)
            . '.normalized.ndjson';
        $temporary = $final . '.part';

        if (\is_file($temporary) && !\unlink($temporary)) {
            throw new RuntimeException(\sprintf('Unable to remove stale output "%s".', $temporary));
        }

        $file = \fopen($temporary, 'wb');
        if ($file === false) {
            throw new RuntimeException(\sprintf('Unable to open output "%s".', $temporary));
        }

        $this->open[$sourcePath] = [
            'file' => $file,
            'temporary' => $temporary,
            'final' => $final,
        ];
    }

    /** @param non-empty-list<array{id: string, name: string}> $records */
    public function write(string $sourcePath, array $records): void
    {
        $session = $this->open[$sourcePath]
            ?? throw new LogicException('Start the sink session before writing records.');
        $payload = '';

        foreach ($records as $record) {
            $payload .= \json_encode(
                $record,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) . "\n";
        }

        $this->writeAll($session['file'], $payload, $session['temporary']);
    }

    public function commit(string $sourcePath): string
    {
        $session = $this->take($sourcePath);
        if (!\fclose($session['file'])) {
            throw new RuntimeException(\sprintf('Unable to close output "%s".', $session['temporary']));
        }

        if (\is_file($session['final']) && !\unlink($session['final'])) {
            throw new RuntimeException(\sprintf('Unable to replace output "%s".', $session['final']));
        }

        if (!\rename($session['temporary'], $session['final'])) {
            throw new RuntimeException(\sprintf(
                'Unable to publish output "%s" as "%s".',
                $session['temporary'],
                $session['final'],
            ));
        }

        return $session['final'];
    }

    public function rollback(string $sourcePath): void
    {
        if (!isset($this->open[$sourcePath])) {
            return;
        }

        $session = $this->take($sourcePath);
        \fclose($session['file']);

        if (\is_file($session['temporary'])) {
            \unlink($session['temporary']);
        }
    }

    public function close(): void
    {
        $sessions = $this->open;
        $this->open = [];

        foreach ($sessions as $session) {
            \fclose($session['file']);

            if (\is_file($session['temporary'])) {
                \unlink($session['temporary']);
            }
        }
    }

    /** @return array{file: resource, temporary: string, final: string} */
    private function take(string $sourcePath): array
    {
        $session = $this->open[$sourcePath]
            ?? throw new LogicException('No sink session exists for this source file.');
        unset($this->open[$sourcePath]);

        return $session;
    }

    /** @param resource $file */
    private function writeAll($file, string $payload, string $path): void
    {
        $offset = 0;
        $bytes = \strlen($payload);

        while ($offset < $bytes) {
            $written = \fwrite($file, \substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException(\sprintf('Unable to write output "%s".', $path));
            }

            $offset += $written;
        }
    }
}
