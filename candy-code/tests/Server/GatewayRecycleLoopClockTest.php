<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Support\ProcessReaper;

/**
 * S-O16 — recycling a wedged workspace host must not freeze the gateway's
 * loop clock. The async ladder waits on loop timers, so a child that traps
 * TERM leaves the loop free to tick (frame pacing) through the whole TERM
 * budget before the KILL rung lands.
 */
final class GatewayRecycleLoopClockTest extends TestCase
{
    public function testAsyncLadderKeepsTheLoopTickingWhileAWedgedChildIsReaped(): void
    {
        $process = \proc_open(['sh', '-c', 'trap "" TERM; while :; do sleep 0.05; done'], [], $pipes);
        $this->assertIsResource($process);
        \usleep(100_000);

        $loop = Loop::get();
        $ticks = 0;
        $maxGap = 0.0;
        $last = \microtime(true);
        $status = 'unset';
        $ticker = $loop->addPeriodicTimer(0.01, static function () use (&$ticks, &$maxGap, &$last): void {
            $now = \microtime(true);
            $maxGap = \max($maxGap, $now - $last);
            $last = $now;
            ++$ticks;
        });
        $guard = $loop->addTimer(10.0, static fn () => $loop->stop());

        $started = \microtime(true);
        ProcessReaper::terminateAndCloseAsync($loop, $process, null, static function (?int $s) use (&$status, $loop): void {
            $status = $s;
            $loop->stop();
        });
        $returned = \microtime(true) - $started;
        $loop->run();
        $loop->cancelTimer($ticker);
        $loop->cancelTimer($guard);

        $this->assertLessThan(0.1, $returned, 'the async variant returns without waiting');
        $this->assertNotSame('unset', $status, 'the ladder completed');
        $this->assertGreaterThan(50, $ticks, 'the loop kept ticking through the TERM budget');
        $this->assertLessThan(0.25, $maxGap, 'no tick gap approaches the ladder budget');
    }
}
