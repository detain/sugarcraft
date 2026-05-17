<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\MultiPump;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * P6.3 — MultiPump multiplexer. Spawns N children, each into its own
 * PTY pair + stdout sink, runs the multiplexer until all complete,
 * asserts each sink received the bytes its child wrote and nothing
 * the other children wrote.
 */
final class MultiPumpTest extends TestCase
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

    public function testEmptyMultiPumpRunsImmediately(): void
    {
        $pump = new MultiPump();
        $this->assertTrue($pump->allDone());
        $this->assertSame([], $pump->run());
        $this->assertSame(0, $pump->size());
    }

    public function testAddRejectsNonResourceSink(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        try {
            $pump = new MultiPump();
            $this->expectException(\InvalidArgumentException::class);
            $pump->add($pair->master(), 'not-a-resource');
        } finally {
            $pair->master()->close();
        }
    }

    public function testRemoveIsIdempotent(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $sink = \fopen('php://temp', 'r+');
        try {
            $pump = new MultiPump();
            $id = $pump->add($pair->master(), $sink);

            $this->assertTrue($pump->has($id));
            $this->assertTrue($pump->remove($id));
            $this->assertFalse($pump->has($id));
            $this->assertFalse($pump->remove($id));
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
            $pair->master()->close();
        }
    }

    public function testFourChildrenStreamsAreIndependent(): void
    {
        $this->requirePtySyscalls();

        $pairs = [];
        $sinks = [];
        $children = [];
        $pump = new MultiPump();
        $ids = [];

        try {
            for ($i = 0; $i < 4; $i++) {
                $system = new PosixPtySystem();
                $pair = $system->open(80, 24);
                $child = $pair->slave()->spawn(
                    ['/bin/bash', '-c', "printf 'session-{$i}-line\\n'"],
                );
                $sink = \fopen('php://temp', 'r+');
                $this->assertIsResource($sink);

                $pairs[$i] = $pair;
                $children[$i] = $child;
                $sinks[$i] = $sink;
                $ids[$i] = $pump->add($pair->master(), $sink, $child);
            }

            $this->assertSame(4, $pump->size());
            $exits = $pump->run();
            $this->assertCount(4, $exits);

            for ($i = 0; $i < 4; $i++) {
                $this->assertSame(0, $children[$i]->wait(), "child {$i} exit");

                \rewind($sinks[$i]);
                $out = \stream_get_contents($sinks[$i]);
                $this->assertIsString($out);

                // Each sink must contain its own marker AND ONLY its own
                // marker — proves the multiplexer routed bytes correctly.
                $this->assertStringContainsString(
                    "session-{$i}-line",
                    $out,
                    "session {$i} sink must carry its own line",
                );
                for ($j = 0; $j < 4; $j++) {
                    if ($j === $i) {
                        continue;
                    }
                    $this->assertStringNotContainsString(
                        "session-{$j}-line",
                        $out,
                        "session {$i} sink must not contain session {$j}'s line",
                    );
                }
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

    public function testStalledChildDoesNotBlockOthers(): void
    {
        $this->requirePtySyscalls();

        // Two sessions:
        //   fast: prints immediately, exits ~0 s.
        //   slow: sleeps 1.5 s before exiting, but never writes.
        //
        // The pump must keep the fast session's output flowing and
        // return as soon as the slow session also reaches exited().
        // The previous-generation polled pump would have wasted CPU
        // spinning on the slow master; the multiplexer must just sit
        // in stream_select between events.
        $sys = new PosixPtySystem();
        $fastPair = $sys->open();
        $slowPair = $sys->open();
        $fastSink = \fopen('php://temp', 'r+');
        $slowSink = \fopen('php://temp', 'r+');

        try {
            $fastChild = $fastPair->slave()->spawn(
                ['/bin/bash', '-c', "printf 'fast-done\\n'"],
            );
            $slowChild = $slowPair->slave()->spawn(
                ['/bin/bash', '-c', 'sleep 1.0'],
            );

            $pump = new MultiPump();
            $pump->add($fastPair->master(), $fastSink, $fastChild);
            $pump->add($slowPair->master(), $slowSink, $slowChild);

            $start = \microtime(true);
            $exits = $pump->run();
            $elapsed = \microtime(true) - $start;

            // The slow child anchors the wall-clock; the fast child's
            // output must arrive WAY before then.
            $this->assertGreaterThan(0.5, $elapsed, 'multiplexer must wait for slow child');
            $this->assertLessThan(5.0, $elapsed, 'multiplexer must not hang past sleep + buffer');

            $this->assertCount(2, $exits);
            $this->assertSame(0, $fastChild->wait());
            $this->assertSame(0, $slowChild->wait());

            \rewind($fastSink);
            $fastOut = \stream_get_contents($fastSink);
            $this->assertStringContainsString('fast-done', $fastOut);
        } finally {
            if (\is_resource($fastSink)) {
                \fclose($fastSink);
            }
            if (\is_resource($slowSink)) {
                \fclose($slowSink);
            }
            $fastPair->master()->close();
            $slowPair->master()->close();
        }
    }

    public function testTickReturnsZeroWhenSessionsIdle(): void
    {
        $this->requirePtySyscalls();

        // No-output child that sleeps briefly. Tick should return 0
        // (nothing ready) before the child exits.
        $sys = new PosixPtySystem();
        $pair = $sys->open();
        $sink = \fopen('php://temp', 'r+');

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 0.3']);
            $pump = new MultiPump();
            $pump->add($pair->master(), $sink, $child);

            // First tick happens with a tiny timeout; child can't have
            // written anything yet because it's sleeping.
            $drained = $pump->tick();
            $this->assertSame(0, $drained, 'no bytes to drain from a sleeping child');

            // Drive to completion.
            $pump->run();
            $this->assertSame(0, $child->wait());
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
            $pair->master()->close();
        }
    }
}
