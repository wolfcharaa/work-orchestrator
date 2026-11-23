<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Internal\Native;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;

/** One cancellable wait for child stream readiness without owning a second loop. @internal */
final class EventLoopWait
{
    /** @param list<ChildProcess> $processes */
    public static function once(
        array $processes,
        ?Cancellation $cancellation,
        float $timeout = 0.05,
    ): void {
        $cancellation?->throwIfRequested();
        $suspension = EventLoop::getSuspension();
        $resumed = false;
        $resume = static function () use (&$resumed, $suspension): void {
            if ($resumed) {
                return;
            }

            $resumed = true;
            $suspension->resume();
        };

        $watchers = [];
        foreach ($processes as $process) {
            foreach ([$process->protocolRead(), $process->stdout(), $process->stderr()] as $stream) {
                $watchers[] = EventLoop::onReadable(
                    $stream,
                    static function (string $_watcher, mixed $_stream) use ($resume): void {
                        $resume();
                    },
                );
            }
            if ($process->hasPendingWrite()) {
                $watchers[] = EventLoop::onWritable(
                    $process->protocolWrite(),
                    static function (string $_watcher, mixed $_stream) use ($resume): void {
                        $resume();
                    },
                );
            }
        }
        $watchers[] = EventLoop::delay($timeout, static function (string $_watcher) use ($resume): void {
            $resume();
        });
        $cancellationId = $cancellation?->subscribe(
            static function (CancelledException $_failure) use ($resume): void {
                $resume();
            },
        );

        try {
            $suspension->suspend();
        } finally {
            if ($cancellation !== null && $cancellationId !== null) {
                $cancellation->unsubscribe($cancellationId);
            }
            foreach ($watchers as $watcher) {
                EventLoop::cancel($watcher);
            }
        }

        $cancellation?->throwIfRequested();
    }

    private function __construct() {}
}
