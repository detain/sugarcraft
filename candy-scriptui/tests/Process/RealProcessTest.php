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
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        // Poll briefly — `exit 0` should finish near-instantly.
        $code = null;
        for ($i = 0; $i < 50 && $code === null; $i++) {
            $code = $proc->exitCode();
            if ($code === null) usleep(10_000);
        }
        $this->assertSame(0, $code);
        $proc->close();
    }

    public function testSpawnPropagatesNonZeroExit(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 7']);
        $code = null;
        for ($i = 0; $i < 50 && $code === null; $i++) {
            $code = $proc->exitCode();
            if ($code === null) usleep(10_000);
        }
        $this->assertSame(7, $code);
        $proc->close();
    }

    public function testCloseIsIdempotentAfterCachedExit(): void
    {
        // Regression for the leak: even when exitCode() has already
        // resolved (and cached) the child's status, close() must still
        // reap the proc_open handle exactly once.
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $first  = $proc->close();   // should call proc_close() under the hood
        $second = $proc->close();   // safe no-op
        $this->assertSame($first, $second);
    }

    public function testTerminateIsNoOpAfterClose(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $proc->close();
        // After close() the underlying handle is gone; terminate() must
        // not fall through to proc_terminate() on a closed resource.
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
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
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $this->assertSame(0, $proc->exitCode());
        // stderrBytes() and stderr() should return the same thing.
        $this->assertSame($proc->stderr(), $proc->stderrBytes());
        $proc->close();
    }
}
