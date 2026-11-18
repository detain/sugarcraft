<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Support\ProcessReaper;

/**
 * The async reap must never park the loop on `proc_close()`.
 *
 * {@see ProcessReaper::terminateAndCloseAsync()} used to reap a child that
 * survived SIGKILL with a blocking `proc_close()`: the gateway's recycle path
 * then froze every connection until the kernel released the child. The reap
 * now polls on loop timers ({@see ProcessReaper::reapWhenExited()}), and these
 * rows pin that: the call returns at once, the loop keeps ticking while the
 * child runs, and the status arrives only once the child is seen gone.
 */
final class ProcessReaperAsyncReapTest extends TestCase
{
    public function testReapWhenExitedReturnsAtOnceAndKeepsTheLoopTickingWhileTheChildRuns(): void
    {
        $loop = new StreamSelectLoop();
        $process = self::spawn('sleep 0.4; exit 3');
        $status = null;
        $settled = false;
        $ticks = 0;

        try {
            $start = \microtime(true);
            ProcessReaper::reapWhenExited($loop, $process, static function (?int $code) use (&$status, &$settled, $loop): void {
                $status = $code;
                $settled = true;
                $loop->stop();
            });
            $callCost = \microtime(true) - $start;

            $this->assertFalse($settled, 'the reap answered synchronously while the child was still running');
            $this->assertLessThan(0.2, $callCost, 'reapWhenExited() blocked the caller');

            $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
                $ticks++;
            });
            $failsafe = $loop->addTimer(5.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($failsafe);
        } finally {
            self::closeQuietly($process);
        }

        $this->assertTrue($settled, 'the reap never settled');
        $this->assertSame(3, $status, 'the status the child exited with was not reported');
        $this->assertGreaterThanOrEqual(3, $ticks, 'the loop did not keep ticking while the child ran');
        $this->assertFalse(\is_resource($process), 'the handle was not released once the child was reaped');
    }

    public function testReapWhenExitedAnswersAtOnceForAnAlreadyExitedChild(): void
    {
        $loop = new StreamSelectLoop();
        $process = self::spawn('exit 0');
        $this->waitUntilGone($process);

        $status = 'unset';
        ProcessReaper::reapWhenExited($loop, $process, static function (?int $code) use (&$status): void {
            $status = $code;
        });

        $this->assertSame(0, $status, 'an exited child must be reaped synchronously with its status');
        $this->assertFalse(\is_resource($process));
    }

    public function testReapWhenExitedAnswersNullForANonResource(): void
    {
        $status = 'unset';
        ProcessReaper::reapWhenExited(new StreamSelectLoop(), null, static function (?int $code) use (&$status): void {
            $status = $code;
        });

        $this->assertNull($status, 'a non-resource is "nothing is running" and answers null');
    }

    /**
     * @return resource
     */
    private static function spawn(string $command)
    {
        $process = \proc_open(
            ['/bin/sh', '-c', $command],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }

        return $process;
    }

    /** @param resource $process */
    private function waitUntilGone($process): void
    {
        $waited = 0.0;
        while (\proc_get_status($process)['running'] === true) {
            \usleep(10_000);
            $waited += 0.01;
            if ($waited >= 5.0) {
                $this->fail('the child did not exit within 5s');
            }
        }
    }

    /** @param resource $process */
    private static function closeQuietly($process): void
    {
        if (\is_resource($process)) {
            \proc_terminate($process, 9);
            \proc_close($process);
        }
    }
}
