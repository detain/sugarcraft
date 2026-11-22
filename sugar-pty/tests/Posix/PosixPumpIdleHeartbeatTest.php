<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

/**
 * The idle heartbeat: onIdle fires on every idle select tick. These cases
 * once drove the deprecated keepalive hook, which fired beside onIdle on
 * the same ticks; it was removed in P1-8e and onIdle carries the beat.
 */
final class PosixPumpIdleHeartbeatTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    public function testOnIdleCalledMultipleTimesDuringIdlePumpLoop(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $idleCount = 0;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(100_000)
                ->withOnIdle(function () use (&$idleCount): void {
                    $idleCount++;
                });

            $pump = new PosixPump();
            $start = \microtime(true);

            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \fclose($stdout);

            // -1 because the `sleep 5` child is still alive when the
            // pump returns (stdin EOF + grace + flush ≈ 0.8s); the
            // pump no longer blocks on wait(). Test pins the new
            // non-blocking contract.
            $this->assertSame(-1, $exitCode);
            $this->assertGreaterThanOrEqual(
                3,
                $idleCount,
                "onIdle should fire at least 3 times over ~350ms with 100ms timeout (got {$idleCount})",
            );
            $this->assertGreaterThan(
                0.3,
                $elapsed,
                "pump should run at least 300ms to accumulate 3+ onIdle calls (elapsed: {$elapsed}s)",
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testPumpRunsWithNoIdleHook(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/cat']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000);

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
        } finally {
            $pair->master()->close();
        }
    }

    public function testOnIdleIsCalledBeforeChildExitCheck(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 0.2; exit 0']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $idleBeforeExit = false;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000)
                ->withOnIdle(function () use (&$idleBeforeExit): void {
                    $idleBeforeExit = true;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertTrue($idleBeforeExit, 'onIdle should have been called before child exit was detected');
        } finally {
            $pair->master()->close();
        }
    }
}
