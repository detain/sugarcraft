<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Posix\PosixChild;

/**
 * Plan item 8.3 — PosixChild::waitAsync(). Verifies the timer-poll
 * promise resolves with the correct exit code without blocking the
 * loop, that cancellation stops the poll timer, and that multiple
 * concurrent waitAsync() calls all resolve.
 *
 * These tests spawn plain proc_open children (no PTY), so they need
 * neither FFI nor /dev/ptmx — only a POSIX host. Loop runs are
 * hard-bounded by safety timers so CI cannot hang.
 *
 * Each test builds its own StreamSelectLoop instead of the global
 * Loop::get() — see ReactPumpTest's class doc for the ExtUvLoop
 * stale-clock rationale.
 */
final class ChildWaitAsyncTest extends TestCase
{
    private function requirePosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
    }

    /** Spawn a /bin/sh -c child and wrap it in a PosixChild handle. */
    private function spawnShell(string $script): PosixChild
    {
        $proc = \proc_open(
            ['/bin/sh', '-c', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $status = \proc_get_status($proc);
        return new PosixChild((int) $status['pid'], $proc);
    }

    public function testStructuralSurface(): void
    {
        $this->assertTrue(\method_exists(PosixChild::class, 'waitAsync'));
        $ret = (new \ReflectionMethod(PosixChild::class, 'waitAsync'))->getReturnType();
        $this->assertSame(PromiseInterface::class, (string) $ret);
    }

    public function testRejectsNonPositivePollInterval(): void
    {
        $this->requirePosix();

        $child = $this->spawnShell('exit 0');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $child->waitAsync(pollIntervalSec: 0.0);
        } finally {
            $child->wait();
        }
    }

    public function testResolvesWithZeroExitCode(): void
    {
        $this->requirePosix();

        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('exit 0');
        $exit = null;

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $child->waitAsync($loop, 0.01)->then(function (int $code) use (&$exit, $loop, $safety): void {
            $exit = $code;
            $loop->cancelTimer($safety);
        });
        $loop->run();

        $this->assertSame(0, $exit);
    }

    public function testResolvesWithNonZeroExitCode(): void
    {
        $this->requirePosix();

        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('exit 5');
        $exit = null;

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $child->waitAsync($loop, 0.01)->then(function (int $code) use (&$exit, $loop, $safety): void {
            $exit = $code;
            $loop->cancelTimer($safety);
        });
        $loop->run();

        $this->assertSame(5, $exit);
    }

    public function testAlreadyExitedChildResolvesImmediately(): void
    {
        $this->requirePosix();

        $child = $this->spawnShell('exit 7');
        // Block until the exit is captured, then waitAsync must resolve
        // synchronously (no loop run needed) from the cached code.
        $this->assertSame(7, $child->wait());

        $exit = null;
        $child->waitAsync()->then(function (int $code) use (&$exit): void {
            $exit = $code;
        });
        $this->assertSame(7, $exit, 'already-exited child must resolve without a loop tick');
    }

    public function testMultipleConcurrentWaitsAllResolve(): void
    {
        $this->requirePosix();

        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('sleep 0.2; exit 4');
        $exits = [];

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $pending = 2;
        $settle = function (int $code) use (&$exits, &$pending, $loop, $safety): void {
            $exits[] = $code;
            if (--$pending === 0) {
                $loop->cancelTimer($safety);
            }
        };
        $child->waitAsync($loop, 0.01)->then($settle);
        $child->waitAsync($loop, 0.01)->then($settle);
        $loop->run();

        $this->assertSame([4, 4], $exits, 'every concurrent waitAsync() must resolve with the exit code');
    }

    public function testCancellationStopsPollingWithoutTouchingChild(): void
    {
        $this->requirePosix();

        $loop = new StreamSelectLoop();
        $child = $this->spawnShell('sleep 3');
        $rejected = null;
        $resolved = false;

        $promise = $child->waitAsync($loop, 0.01);
        $promise->then(
            function () use (&$resolved): void {
                $resolved = true;
            },
            function (\Throwable $e) use (&$rejected): void {
                $rejected = $e;
            },
        );

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->addTimer(0.05, function () use ($promise, $loop, $safety): void {
            $promise->cancel();
            $loop->cancelTimer($safety);
        });

        $start = \microtime(true);
        $loop->run();
        $elapsed = \microtime(true) - $start;

        $this->assertFalse($resolved);
        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertLessThan(
            2.0,
            $elapsed,
            'cancel() must cancel the poll timer — the loop must not wait out the sleeping child',
        );
        $this->assertFalse($child->exited(), 'cancellation must not signal or reap the child');

        $child->kill(9);
        $this->assertGreaterThan(0, $child->wait());
    }
}
