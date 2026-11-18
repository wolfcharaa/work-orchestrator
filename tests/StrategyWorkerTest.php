<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Tests;

use Fedor\WorkOrchestrator\Event\EventEnvelope;
use Fedor\WorkOrchestrator\Event\TaskSucceeded;
use Fedor\WorkOrchestrator\File\FileOrder;
use Fedor\WorkOrchestrator\File\FileWorkQueue;
use Fedor\WorkOrchestrator\Hook\TaskBoundary;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Tests\Fixture\BytesProgressed;
use Fedor\WorkOrchestrator\Tests\Fixture\NestedBytesStrategy;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class StrategyWorkerTest
{
    public function testStrategyCapabilitiesOwnOrderBootstrapAndNestedWorkerReuse(): void
    {
        $small = self::temporaryFile('123');
        $large = self::temporaryFile('1234567');
        $lifecycle = self::temporaryFile('');
        $applicationEvents = [];
        $taskEvents = [];
        $afterTaskSawTerminalEvent = [];

        try {
            $queue = FileWorkQueue::discover(
                [$small, $large],
                order: FileOrder::largestFirst(),
            );
            $result = Orchestrator::fromQueue($queue)
                ->primaryWorkers(1)
                ->childWorkersPerPrimary(2)
                ->onApplicationEvent(static function (EventEnvelope $event) use (&$applicationEvents): void {
                    $applicationEvents[] = $event;
                })
                ->onTaskLifecycle(static function (EventEnvelope $event) use (&$taskEvents): void {
                    $taskEvents[$event->taskId][] = $event;
                })
                ->afterTask(static function (TaskBoundary $task) use (
                    &$taskEvents,
                    &$afterTaskSawTerminalEvent,
                ): void {
                    $events = $taskEvents[$task->taskId] ?? [];
                    $last = $events === [] ? null : $events[\count($events) - 1];
                    $afterTaskSawTerminalEvent[] = $last?->event instanceof TaskSucceeded;
                })
                ->run(new NestedBytesStrategy($lifecycle, factor: 3));

            Assert::same(\array_map(
                static fn($result): string => $result->id->value,
                $result->results(),
            ), [$large, $small]);
            $largeResult = self::strategyResult($result->value(new WorkId($large)));
            $smallResult = self::strategyResult($result->value(new WorkId($small)));

            Assert::same($largeResult['bytes'], 21);
            Assert::same($smallResult['bytes'], 9);
            Assert::same($largeResult['primaryProcess'], $smallResult['primaryProcess']);

            $largeChildren = self::childProcesses($largeResult);
            $smallChildren = self::childProcesses($smallResult);
            Assert::count($largeChildren, 2);
            Assert::same($smallChildren, $largeChildren);
            Assert::true(!\in_array($largeResult['primaryProcess'], $largeChildren, true));

            Assert::same(\file($lifecycle, \FILE_IGNORE_NEW_LINES), [
                \sprintf('boot:%d', $largeResult['primaryProcess']),
                \sprintf('close:%d', $largeResult['primaryProcess']),
            ]);
            Assert::count($applicationEvents, 6);
            Assert::true(\array_reduce(
                $applicationEvents,
                static fn(bool $valid, EventEnvelope $event): bool => $valid
                    && $event->depth === 2
                    && $event->event instanceof BytesProgressed,
                true,
            ));
            Assert::same($afterTaskSawTerminalEvent, [true, true]);
        } finally {
            \unlink($small);
            \unlink($large);
            \unlink($lifecycle);
        }
    }

    private static function temporaryFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'work-orchestrator-strategy-');
        if ($path === false || \file_put_contents($path, $contents) !== \strlen($contents)) {
            throw new LogicException('Failed to prepare a Strategy test file.');
        }

        return $path;
    }

    /**
     * @return array{
     *     bytes: int,
     *     primaryProcess: int,
     *     children: list<array{process: int, value: int}>
     * }
     */
    private static function strategyResult(mixed $result): array
    {
        if (!\is_array($result)
            || !isset(
                $result['bytes'],
                $result['primaryProcess'],
                $result['children'],
            )
            || !\is_int($result['bytes'])
            || !\is_int($result['primaryProcess'])
            || !\is_array($result['children'])) {
            throw new LogicException('Strategy returned an invalid test result.');
        }

        /** @var list<array{process: int, value: int}> $children */
        $children = $result['children'];

        return [
            'bytes' => $result['bytes'],
            'primaryProcess' => $result['primaryProcess'],
            'children' => $children,
        ];
    }

    /**
     * @param array{
     *     bytes: int,
     *     primaryProcess: int,
     *     children: list<array{process: int, value: int}>
     * } $result
     *
     * @return list<int>
     */
    private static function childProcesses(array $result): array
    {
        $processes = \array_values(\array_unique(\array_column($result['children'], 'process')));
        \sort($processes);

        return $processes;
    }

}
