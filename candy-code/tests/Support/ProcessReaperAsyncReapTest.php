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

    /**
     * Regression: the leader-only "gone" check declared a containment-wrapped
     * child gone the moment its wrapper died on SIGTERM, so a TERM-ignoring
     * descendant in the group never received signal 9. The async ladder must
     * keep escalating until the group is empty.
     */
    public function testTerminateAndCloseAsyncReachesATermIgnoringDescendantAfterTheWrapperDiesOnTerm(): void
    {
        [$wrapped, $wrapperPid, $grandchild] = self::spawnWrapperWithTermIgnoringGrandchild();
        $loop = new StreamSelectLoop();
        $settled = false;

        try {
            ProcessReaper::terminateAndCloseAsync($loop, $wrapped, $wrapperPid, static function () use (&$settled, $loop): void {
                $settled = true;
                $loop->stop();
            });
            $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            self::assertTrue($settled, 'the async teardown must settle');
            self::assertFalse(self::groupMemberAlive($grandchild), 'the TERM-ignoring descendant must be down once the ladder settles');
        } finally {
            self::killGroupQuietly($wrapperPid, $grandchild, $wrapped);
        }
    }

    /** Sync twin of the regression above: terminateAndAwaitExit() must not report gone while the group lives. */
    public function testTerminateAndAwaitExitReachesATermIgnoringDescendantAfterTheWrapperDiesOnTerm(): void
    {
        [$wrapped, $wrapperPid, $grandchild] = self::spawnWrapperWithTermIgnoringGrandchild();

        try {
            self::assertTrue(ProcessReaper::terminateAndAwaitExit($wrapped, $wrapperPid));
            self::assertFalse(self::groupMemberAlive($grandchild), 'terminateAndAwaitExit reports gone only once the group is empty');
        } finally {
            self::killGroupQuietly($wrapperPid, $grandchild, $wrapped);
        }
    }

    /**
     * A setsid-led shell whose background sleeper ignores SIGTERM, with the
     * wrapper itself dying on TERM. Readiness is signalled by the sleeper after
     * its trap is armed, so the teardown never races an unarmed sleeper.
     *
     * @return array{0: resource, 1: int, 2: int} the wrapper, its pid, and the grandchild pid
     */
    private static function spawnWrapperWithTermIgnoringGrandchild(): array
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('ext-posix is required to inspect the process group');
        }
        $setsid = null;
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
            if (\is_executable($candidate)) {
                $setsid = $candidate;
                break;
            }
        }
        if ($setsid === null) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        $marker = \tempnam(\sys_get_temp_dir(), 'reaper-group-');
        self::assertNotFalse($marker, 'could not create the grandchild marker file');
        $ready = $marker . '.ready';

        try {
            $wrapped = \proc_open(
                [$setsid, 'sh', '-c', '(trap "" TERM; : > "$0.ready"; exec sleep 30) & echo $! > "$0"; wait', $marker],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($wrapped);
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }
            $wrapperPid = (int) (\proc_get_status($wrapped)['pid'] ?? 0);

            $deadline = \hrtime(true) / 1_000_000_000 + 3.0;
            while ((!\is_file($ready) || \trim((string) \file_get_contents($marker)) === '') && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($ready, 'the sleeper never armed its TERM trap');
            $grandchild = (int) \trim((string) \file_get_contents($marker));
            self::assertGreaterThan(0, $grandchild, 'the wrapper never reported its grandchild');
            self::assertTrue(self::groupMemberAlive($grandchild), 'the grandchild must be alive before the teardown');
        } finally {
            @\unlink($marker);
            @\unlink($ready);
        }

        return [$wrapped, $wrapperPid, $grandchild];
    }

    /** A zombie is gone for this purpose: it has released its memory and cannot run. */
    private static function groupMemberAlive(int $pid): bool
    {
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');

        return $stat !== false && \preg_match('/\)\s+Z/', $stat) !== 1;
    }

    /** @param resource $wrapped */
    private static function killGroupQuietly(int $wrapperPid, int $grandchild, $wrapped): void
    {
        if ($grandchild > 0 && self::groupMemberAlive($grandchild)) {
            @\posix_kill($grandchild, 9);
        }
        if ($wrapperPid > 0) {
            @\posix_kill(-$wrapperPid, 9);
        }
        if (\is_resource($wrapped)) {
            \proc_close($wrapped);
        }
    }

    public function testGraceDeadlinesMeasureOnAMonotonicClock(): void
    {
        // A wall-clock step (NTP) must not stretch or cut the TERM/KILL window.
        // A live clock step cannot be staged in a test, so the source is the guard.
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/ProcessReaper.php');

        self::assertStringNotContainsString('microtime(', $source);
        self::assertStringContainsString('hrtime(true)', $source);
    }
}
