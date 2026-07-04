<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Posix\MultiPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

/**
 * Plan item 8.4 — MultiPump::runAsync(). Verifies the event-loop
 * variant resolves with the same session-id → exit-code map as run()
 * once every child has exited, that each sink only carries its own
 * child's bytes, and that an empty multiplexer resolves immediately.
 *
 * Loop runs are hard-bounded by safety timers so CI cannot hang.
 *
 * Each test builds its own StreamSelectLoop instead of the global
 * Loop::get() — see ReactPumpTest's class doc for the ExtUvLoop
 * stale-clock rationale.
 */
final class MultiPumpRunAsyncTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable on this host.');
        }
    }

    public function testStructuralSurface(): void
    {
        $this->assertTrue(\method_exists(MultiPump::class, 'runAsync'));
        $ret = (new \ReflectionMethod(MultiPump::class, 'runAsync'))->getReturnType();
        $this->assertSame(PromiseInterface::class, (string) $ret);
    }

    public function testEmptyMultiPumpResolvesImmediately(): void
    {
        $pump = new MultiPump();
        $exits = null;
        $pump->runAsync()->then(function (array $map) use (&$exits): void {
            $exits = $map;
        });
        $this->assertSame([], $exits, 'empty multiplexer must resolve synchronously with []');
    }

    public function testTwoChildrenResolveAfterBothExit(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $sys = new PosixPtySystem();
        $pairs = [];
        $sinks = [];
        $children = [];
        $pump = new MultiPump();
        $ids = [];
        $exits = null;

        try {
            // Tight cadence + short flush keeps the two-child round trip
            // well under the safety cap.
            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000)
                ->withFlushDeadlineSec(0.2);

            foreach ([0, 1] as $i) {
                $pair = $sys->open(80, 24);
                $child = $pair->slave()->spawn(
                    ['/bin/bash', '-c', "printf 'async-session-{$i}\\n'; exit {$i}"],
                );
                $sink = \fopen('php://temp', 'r+');
                $this->assertIsResource($sink);

                $pairs[$i] = $pair;
                $children[$i] = $child;
                $sinks[$i] = $sink;
                $ids[$i] = $pump->add($pair->master(), $sink, $child, $opts);
            }

            $safety = $loop->addTimer(8.0, static fn () => $loop->stop());
            $pump->runAsync($loop)->then(function (array $map) use (&$exits, $loop, $safety): void {
                $exits = $map;
                $loop->cancelTimer($safety);
            });

            $start = \microtime(true);
            $loop->run();
            $elapsed = \microtime(true) - $start;

            $this->assertIsArray($exits, 'runAsync promise must resolve once both children exit');
            $this->assertSame([$ids[0] => 0, $ids[1] => 1], $exits);
            $this->assertTrue($pump->allDone());
            $this->assertLessThan(
                7.0,
                $elapsed,
                'loop must exit when both session pumps deregister — leaks pin it to the safety cap',
            );

            foreach ([0, 1] as $i) {
                \rewind($sinks[$i]);
                $out = (string) \stream_get_contents($sinks[$i]);
                $this->assertStringContainsString("async-session-{$i}", $out, "sink {$i} carries its own line");
                $other = 1 - $i;
                $this->assertStringNotContainsString(
                    "async-session-{$other}",
                    $out,
                    "sink {$i} must not carry session {$other}'s line",
                );
            }
        } finally {
            foreach ($sinks as $sink) {
                if (\is_resource($sink)) {
                    \fclose($sink);
                }
            }
            foreach ($pairs as $pair) {
                $pair->master()->close();
            }
        }
    }
}
