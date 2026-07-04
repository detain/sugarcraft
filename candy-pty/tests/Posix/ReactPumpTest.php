<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * Plan item 8.1 — ReactPHP-compatible pump. Verifies the event-loop
 * pump forwards child output through the loop (no blocking select),
 * resolves with the sync pump's exit-code contract, and deregisters
 * everything on stop()/finish so the loop can exit.
 *
 * Every loop run is hard-bounded by a safety timer (watchdog convention
 * from PtyPoolReactLoopTest) so CI cannot hang; the elapsed-time
 * assertions double as leak detectors — a leaked read stream or timer
 * would pin the loop until the safety cap.
 *
 * Each test builds its own StreamSelectLoop instead of the global
 * Loop::get(): the global loop may be ExtUvLoop, whose internal clock
 * only advances while the loop runs — a relative timer armed after
 * ~20 s of non-loop tests is instantly overdue on the next run() and
 * the safety cap fires immediately. A fresh per-test loop also keeps
 * registrations from leaking across test boundaries.
 */
final class ReactPumpTest extends TestCase
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
        $this->assertTrue(\class_exists(ReactPump::class));
        $this->assertTrue(\method_exists(ReactPump::class, 'start'));
        $this->assertTrue(\method_exists(ReactPump::class, 'stop'));
        $this->assertTrue(\method_exists(ReactPump::class, 'isRunning'));

        $ret = (new \ReflectionMethod(ReactPump::class, 'start'))->getReturnType();
        $this->assertSame(PromiseInterface::class, (string) $ret);
    }

    public function testStartRejectsNonResourceStreams(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        try {
            $pump = new ReactPump();
            $this->expectException(\InvalidArgumentException::class);
            $pump->start($pair->master(), stdinStream: 'not-a-resource');
        } finally {
            $pair->master()->close();
        }
    }

    public function testForwardsChildOutputToCallbackViaLoop(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $collected = '';
        $exit = null;

        try {
            $child = $pair->slave()->spawn(
                ['/bin/bash', '-c', "printf 'react-pump-hello\\n'"],
            );

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            $pump->start(
                $pair->master(),
                child: $child,
                onData: function (string $bytes) use (&$collected): void {
                    $collected .= $bytes;
                },
            )->then(function (int $code) use (&$exit, $loop, $safety): void {
                $exit = $code;
                $loop->cancelTimer($safety);
            });

            $this->assertTrue($pump->isRunning());
            $start = \microtime(true);
            $loop->run();
            $elapsed = \microtime(true) - $start;

            $this->assertStringContainsString('react-pump-hello', $collected);
            $this->assertSame(0, $exit, 'pump promise must resolve with the child exit code');
            $this->assertFalse($pump->isRunning());
            $this->assertLessThan(
                4.0,
                $elapsed,
                'loop must exit as soon as the pump deregisters — a leaked stream/timer pins it to the safety cap',
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testWritesChildOutputToStdoutStream(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $sink = \fopen('php://temp', 'r+');
        $exit = null;

        try {
            $child = $pair->slave()->spawn(
                ['/bin/bash', '-c', "printf 'sink-bytes\\n'"],
            );

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            $pump->start($pair->master(), stdoutStream: $sink, child: $child)
                ->then(function (int $code) use (&$exit, $loop, $safety): void {
                    $exit = $code;
                    $loop->cancelTimer($safety);
                });
            $loop->run();

            $this->assertSame(0, $exit);
            \rewind($sink);
            $this->assertStringContainsString('sink-bytes', (string) \stream_get_contents($sink));
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
            $pair->master()->close();
        }
    }

    public function testResolvesWithNonZeroExitCode(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open();
        $exit = null;

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'exit 3']);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            // Tight select cadence keeps exit detection + flush window short.
            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000)
                ->withFlushDeadlineSec(0.2);
            $pump->start($pair->master(), child: $child, opts: $opts)
                ->then(function (int $code) use (&$exit, $loop, $safety): void {
                    $exit = $code;
                    $loop->cancelTimer($safety);
                });
            $loop->run();

            $this->assertSame(3, $exit);
        } finally {
            $pair->master()->close();
        }
    }

    public function testStopDeregistersAndResolvesMinusOneForLiveChild(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open();
        $exit = null;

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            $pump->start($pair->master(), child: $child)
                ->then(function (int $code) use (&$exit, $loop, $safety): void {
                    $exit = $code;
                    $loop->cancelTimer($safety);
                });
            $loop->addTimer(0.1, static fn () => $pump->stop());

            $start = \microtime(true);
            $loop->run();
            $elapsed = \microtime(true) - $start;

            // Sync-pump contract: child still running at teardown → -1,
            // caller owns kill + wait.
            $this->assertSame(-1, $exit);
            $this->assertFalse($pump->isRunning());
            $this->assertLessThan(
                4.0,
                $elapsed,
                'stop() must remove every loop registration so the loop exits immediately',
            );

            $child->kill(9);
            $child->wait();
        } finally {
            $pair->master()->close();
        }
    }

    public function testStopIsIdempotentAfterNaturalFinish(): void
    {
        $this->requirePtySyscalls();

        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open();
        $exit = null;

        try {
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'exit 0']);

            $pump = new ReactPump($loop);
            $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
            $pump->start($pair->master(), child: $child)
                ->then(function (int $code) use (&$exit, $loop, $safety): void {
                    $exit = $code;
                    $loop->cancelTimer($safety);
                });
            $loop->run();

            $this->assertSame(0, $exit);
            $pump->stop();
            $pump->stop();
            $this->assertFalse($pump->isRunning());
        } finally {
            $pair->master()->close();
        }
    }
}
