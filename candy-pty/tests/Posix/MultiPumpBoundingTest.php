<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Posix\MultiPump;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * E717 — MultiPump::run()'s optional caller-bound.
 *
 * run() without a deadline is unbounded until every session reaches its
 * done state (child exited + flush window, or master EOF) — a live-but-
 * silent child pins it forever. run($pumpDeadlineUs) is the supervisor-
 * scoped opt-in bound: expiry returns the PARTIAL exit map without
 * touching session state, so a later run() resumes draining the same
 * multiplexer. The bound hands control back; it never kills anything.
 */
final class MultiPumpBoundingTest extends TestCase
{
    /** @var list<Child> */
    private array $children = [];

    /** @var list<resource> */
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            try {
                if (!$child->exited()) {
                    $child->kill(Child::SIGKILL);
                }
                $child->wait();
            } catch (\Throwable) {
            }
        }
        $this->children = [];
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
        }
        $this->streams = [];
    }

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
            $this->markTestSkipped('/bin/bash is required by the pump fixtures.');
        }
    }

    public function testRunRejectsNonPositiveDeadline(): void
    {
        $pump = new MultiPump();
        $this->expectException(\InvalidArgumentException::class);
        $pump->run(0);
    }

    public function testRunRejectsNegativeDeadline(): void
    {
        $pump = new MultiPump();
        $this->expectException(\InvalidArgumentException::class);
        $pump->run(-1);
    }

    public function testEmptyPumpWithDeadlineStillReturnsImmediately(): void
    {
        $pump = new MultiPump();
        $started = \microtime(true);
        $this->assertSame([], $pump->run(1_000_000));
        $this->assertLessThan(1.0, \microtime(true) - $started);
    }

    public function testDeadlineReturnsPartialStateAndSecondRunResumes(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        // Writes a chunk early, then stays alive and silent: the session
        // can only end via child-exit + flush, so an unbounded run() would
        // pin this test for the full sleep.
        $child = $pair->slave()->spawn(
            ['/bin/bash', '-c', "printf 'first-chunk\\n'; sleep 30"],
        );
        $this->children[] = $child;
        $sink = \fopen('php://temp', 'r+');
        $this->streams[] = $sink;

        $pump = new MultiPump();
        $id = $pump->add($pair->master(), $sink, $child);

        $started = \microtime(true);
        $first = $pump->run(300_000);
        $elapsed = \microtime(true) - $started;

        // 1. The bound fired — not the session-done path (impossible for a
        //    live child) and not any hidden clock: 30s of sleep elapsed
        //    nowhere near the ceiling.
        $this->assertLessThan(8.0, $elapsed, 'deadline must return control');
        // 2. Nothing was killed, no session was marked done: state intact.
        $this->assertFalse($child->exited());
        $this->assertFalse($pump->allDone(), 'early return must not mark sessions done');
        $this->assertTrue($pump->has($id));
        $this->assertSame([$id => null], $first, 'partial map: live child has no exit code yet');
        // 3. Bytes produced before the bound were still delivered.
        \rewind($sink);
        $this->assertStringContainsString('first-chunk', (string) \stream_get_contents($sink));

        // Now supply the event-driven exit (caller kill) and resume: the
        // SAME multiplexer finishes through its normal session-done path.
        $child->kill(Child::SIGKILL);
        $child->wait();

        $second = $pump->run(5_000_000);
        $this->assertTrue($pump->allDone(), 'resumed run must drive the dead-child flush to done');
        $this->assertArrayHasKey($id, $second);

        $pair->master()->close();
    }
}
