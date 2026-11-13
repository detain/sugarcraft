<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use SugarCraft\ScriptUi\Process\RealProcess;
use PHPUnit\Framework\TestCase;

final class RealProcessTest extends TestCase
{
    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('RealProcessTest requires Unix commands (/bin/sh) not available on Windows.');
        }
    }

    public function testSpawnReadsExitCodeFromTrueCommand(): void
    {
        // scriptui-50: bounded wait() replaces the hand-rolled 50x10ms poll.
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        $this->assertSame(0, $proc->wait());
    }

    public function testSpawnPropagatesNonZeroExit(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 7']);
        $this->assertSame(7, $proc->wait());
    }

    public function testCloseIsIdempotentAfterCachedExit(): void
    {
        // Regression for the leak: even when the child's status has already
        // resolved (and cached), close() must still hand back that exit code
        // exactly once — repeat calls are safe no-ops.
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        $code   = $proc->wait();     // reaps and caches
        $first  = $proc->close();    // must not double-reap
        $second = $proc->close();    // safe no-op
        $this->assertSame($code, $first);
        $this->assertSame($first, $second);
    }

    public function testTerminateIsNoOpAfterClose(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        $proc->wait();               // full reap
        // After the handle is gone; terminate() must not fall through to
        // proc_terminate() on a closed resource.
        $proc->terminate();
        $this->assertSame(0, $proc->close());
    }

    public function testSpawnWithCapturedStdout(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'echo hello world'],
            captureStdout: true,
            captureStderr: false,
        );
        $this->assertSame(0, $proc->wait());
        $this->assertSame("hello world\n", $proc->stdout());
        $this->assertSame('', $proc->stderr());
        $proc->close();
    }

    public function testSpawnWithCapturedStderr(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'echo error >&2'],
            captureStdout: false,
            captureStderr: true,
        );
        $this->assertSame(0, $proc->wait());
        $this->assertSame('', $proc->stdout());
        $this->assertSame("error\n", $proc->stderr());
        $proc->close();
    }

    public function testSpawnWithBothCaptured(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'echo out; echo err >&2'],
            captureStdout: true,
            captureStderr: true,
        );
        $this->assertSame(0, $proc->wait());
        $this->assertSame("out\n", $proc->stdout());
        $this->assertSame("err\n", $proc->stderr());
        $proc->close();
    }

    public function testTerminateWhenRunning(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'sleep 60'],
            captureStdout: false,
            captureStderr: false,
        );
        // Process should be running at this point
        $this->assertNull($proc->exitCode());
        $proc->terminate();
        // M28: the old test asserted nothing about the child actually dying.
        // close() must reap it (signal death surfaces as 128+SIGTERM, or as
        // -1 when PHP's own proc_get_status wins the reap race — both prove
        // the child is gone; either way exited() is true once reaped).
        $code = $proc->close();
        $this->assertTrue($proc->exited(), 'child must be reaped after terminate+close');
        $this->assertContains($code, [-1, 143], 'SIGTERM death must yield 143 or the -1 reaped-by-fallback convention');
    }

    /**
     * Cluster 5: a child that traps and ignores SIGTERM used to hang close()
     * forever (the inner wait() polls `while (true)`). close() now bounds the
     * grace window and escalates to SIGKILL.
     */
    public function testCloseEscalatesToKillAfterGraceWhenTermIgnored(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'trap "" TERM; sleep 60; exit 0']);
        \usleep(200_000); // let the shell arm its TERM trap before we signal
        $proc->terminate();

        $started = \microtime(true);
        $code = $proc->close();
        $elapsed = \microtime(true) - $started;

        $this->assertLessThan(
            RealProcess::TERM_GRACE_SECONDS + 3.0,
            $elapsed,
            'close() must escalate to SIGKILL within the grace window, not hang',
        );
        $this->assertTrue($proc->exited());
        $this->assertContains($code, [-1, 128 + 9], 'SIGKILL death must yield 137 or the -1 reaped-by-fallback convention');
    }

    /**
     * terminate() signals through sugar-pty's FFI Libc::kill, so the old
     * `function_exists('posix_kill')` gate silently disabled the signal on
     * FFI-only hosts (and left close() to hang). The gate must stay gone.
     */
    public function testTerminateIsNotGatedOnThePosixKillExtension(): void
    {
        // scriptui-53 KEEP (negation-pin): the contract is that a gate is
        // ABSENT — the old function_exists('posix_kill') guard silently
        // disabled the signal on FFI-only hosts, and no single-host
        // behaviour run can witness its regression. The escalate behaviour
        // itself is proven live in testCloseEscalatesToKillAfterGrace.
        $source = \file_get_contents(__DIR__ . '/../../src/Process/RealProcess.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("function_exists('posix_kill')", $source);
        $this->assertStringContainsString('SIGKILL', $source, 'close() must carry the escalation rung');
    }

    public function testTerminateIsIdempotentWhileRunning(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'sleep 60']);
        $this->assertNull($proc->exitCode());
        $proc->terminate();
        // A second terminate() must be a no-op (no re-signal of a PID that
        // may already be dead), and close() must still reap exactly once
        // without hanging.
        $proc->terminate();
        $code = $proc->close();
        $this->assertSame($code, $proc->close());
    }

    public function testTerminateAfterNaturalExitIsNoOp(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        $this->assertSame(0, $proc->wait());
        // The child already exited on its own: terminate() must not signal
        // the (possibly recycled) PID, and close() still reaps normally.
        $proc->terminate();
        $this->assertSame(0, $proc->close());
    }

    public function testStdoutBytesReturnsSameAsStdout(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'echo hello world'],
            captureStdout: true,
            captureStderr: false,
        );
        $this->assertSame(0, $proc->wait());
        // stdoutBytes() and stdout() should return the same thing.
        $this->assertSame($proc->stdout(), $proc->stdoutBytes());
        $proc->close();
    }

    public function testStderrBytesReturnsSameAsStderr(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'echo error >&2'],
            captureStdout: false,
            captureStderr: true,
        );
        $this->assertSame(0, $proc->wait());
        // stderrBytes() and stderr() should return the same thing.
        $this->assertSame($proc->stderr(), $proc->stderrBytes());
        $proc->close();
    }
}
