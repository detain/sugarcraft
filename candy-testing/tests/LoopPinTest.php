<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Testing\LoopPin;

/**
 * @covers \SugarCraft\Testing\LoopPin
 */
final class LoopPinTest extends TestCase
{
    public function testPinStableClockInstallsAStreamSelectLoopAsTheSharedLoop(): void
    {
        $loop = LoopPin::pinStableClock();

        self::assertInstanceOf(StreamSelectLoop::class, $loop);
        self::assertSame($loop, Loop::get(), 'the pinned loop must be the one Loop::get() hands out');
    }

    public function testPinStableClockIsIdempotent(): void
    {
        $first  = LoopPin::pinStableClock();
        $second = LoopPin::pinStableClock();

        // A second call must not discard the first loop — a suite that has
        // already registered watchers on it would silently lose them.
        self::assertSame($first, $second);
    }

    /**
     * The idempotence latch has two halves, and only the second one carries the
     * safety property: if something else swapped the shared loop out after we
     * pinned, returning our old pin would hand the caller an orphan that
     * `Loop::get()` no longer serves — watchers registered on it would never
     * run. Re-pinning is the correct answer.
     */
    public function testPinStableClockRePinsWhenSomethingElseSwappedTheSharedLoop(): void
    {
        $first = LoopPin::pinStableClock();

        // Stand in for another bootstrap, or a nested harness, installing its
        // own loop on top of ours.
        $interloper = new StreamSelectLoop();
        Loop::set($interloper);
        self::assertNotSame($first, $interloper, 'the interloper must be a distinct instance');

        $repinned = LoopPin::pinStableClock();

        self::assertSame(
            $repinned,
            Loop::get(),
            'a repeat pin must return the loop Loop::get() actually serves, not a stale orphan',
        );
        self::assertNotSame($interloper, $repinned, 'the interloper must not survive as the pin');
    }

    /**
     * `Loop::get()` registers a shutdown function that runs the loop IT
     * created. If that loop owns a periodic timer, that shutdown run never
     * returns and the process hangs — pinning afterwards does not on its own
     * save you, because the hook still holds the discarded loop.
     *
     * `pinStableClock()` therefore calls `Loop::stop()`, which sets the flag the
     * hook reads by reference and makes it skip its `run()`. This test drives
     * the whole thing in a child process because a shutdown hook can only be
     * observed at shutdown.
     */
    public function testPinningDisarmsAnAlreadyRegisteredShutdownHook(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script   = <<<PHP
            <?php
            require '{$autoload}';
            // Deliberately the wrong order: create the loop (and its shutdown
            // hook) first, arm a periodic timer so a shutdown run() could never
            // finish, and only then pin.
            \$loop = \\React\\EventLoop\\Loop::get();
            \$loop->addPeriodicTimer(0.01, static function (): void {});
            \\SugarCraft\\Testing\\LoopPin::pinStableClock();
            echo 'exited-cleanly';
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'looppin') . '.php';
        file_put_contents($file, $script);

        try {
            $process = proc_open(
                [PHP_BINARY, $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $deadline = microtime(true) + 10.0;
            $stdout   = '';
            $status   = proc_get_status($process);
            while ($status['running'] && microtime(true) < $deadline) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                usleep(20_000);
                $status = proc_get_status($process);
            }
            $hung = $status['running'];
            if ($hung) {
                proc_terminate($process, 9);
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            self::assertFalse($hung, 'the child hung — the stale shutdown hook ran its loop');
            self::assertSame(0, $status['exitcode'], 'the child did not exit cleanly');
            self::assertStringContainsString('exited-cleanly', $stdout);
        } finally {
            @unlink($file);
        }
    }

    public function testStreamSelectLoopIsReportedFreeOfStaleClockRisk(): void
    {
        self::assertFalse(LoopPin::hasStaleClockRisk(new StreamSelectLoop()));
    }

    /**
     * The real target of the allowlist, not just a stand-in: ext-uv is the loop
     * autodetection actually picks when the extension is present, and the one
     * whose stale-clock behaviour is measured in the {@see LoopPin} docblock.
     */
    public function testExtUvLoopIsReportedAsAtRisk(): void
    {
        if (!extension_loaded('uv')) {
            self::markTestSkipped('ext-uv is not installed, so ExtUvLoop cannot be constructed');
        }

        self::assertTrue(LoopPin::hasStaleClockRisk(new \React\EventLoop\ExtUvLoop()));
    }

    public function testUnrecognisedLoopsAreReportedAsAtRisk(): void
    {
        // Allowlist semantics: anything not known to recompute its clock every
        // iteration must read as risky rather than silently safe.
        $exotic = $this->createMock(\React\EventLoop\LoopInterface::class);

        self::assertTrue(LoopPin::hasStaleClockRisk($exotic));
    }

    /**
     * The behavioural guarantee, not just the type: a timer armed after a long
     * stretch of synchronous idle must still wait its full delay.
     *
     * This is the regression test for the stale-clock trap described on
     * {@see LoopPin}. Under ExtUvLoop the same sequence returns almost
     * instantly, because libuv measures the deadline against a cached clock
     * that is only refreshed once per loop iteration.
     *
     * Caveat for anyone reading a green CI run: on a box WITHOUT ext-uv this
     * test is vacuous. `Loop::get()` would hand back a `StreamSelectLoop`
     * whether or not `pinStableClock()` was ever called, so the assertion below
     * cannot fail there and proves nothing about the pin. It only has teeth
     * where the autodetected loop would have been an at-risk one.
     */
    public function testPinnedLoopTimerIsNotShortenedByIdleTimeOutsideTheLoop(): void
    {
        $loop = LoopPin::pinStableClock();

        // Prime it: an earlier short run() is what would have populated a
        // cached-clock loop's notion of "now" in the first place.
        $loop->addTimer(0.001, static function (): void {});
        $loop->run();

        // Stand in for the long stretch of ordinary synchronous test code that
        // runs between one test's loop burst and the next's. Kept small so the
        // suite stays fast; the trap is linear in this value, so any idle at
        // all is enough to expose it.
        usleep(300_000);

        $armed   = 0.2;
        $started = microtime(true);
        $loop->addTimer($armed, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $elapsed = microtime(true) - $started;

        // A stale-clock loop would return in ~0s here, having treated the timer
        // as already overdue by (idle - armed).
        self::assertGreaterThanOrEqual(
            $armed * 0.9,
            $elapsed,
            'timer fired early — the shared loop is computing deadlines against a stale clock',
        );
    }
}
