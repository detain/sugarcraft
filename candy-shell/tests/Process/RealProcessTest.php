<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Process;

use SugarCraft\Shell\Process\RealProcess;
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
        // terminate() should not crash and should allow close
        $proc->close();
    }
}
