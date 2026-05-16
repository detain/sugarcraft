<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Process;
use SugarCraft\Pty\Posix\PosixProcess;
use SugarCraft\Pty\PtyException;

/**
 * Round-trip tests for {@see PosixProcess}: spawn real binaries
 * (`/bin/echo`, `/bin/sh`, `/bin/sleep`) and assert the lifecycle
 * matches the upstream creack/pty.Cmd contract.
 *
 * All long-running test paths have a wall-clock deadline so a
 * regression in wait()/drain can't hang the suite indefinitely.
 */
final class PosixProcessTest extends TestCase
{
    private function requireRealBinaries(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PosixProcess is POSIX-only.');
        }
        foreach (['/bin/echo', '/bin/sh', '/bin/sleep'] as $bin) {
            if (!\is_executable($bin)) {
                $this->markTestSkipped("Required binary {$bin} is not executable on this host.");
            }
        }
    }

    public function testImplementsContractProcess(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(['/bin/echo', 'hello'], null, true, false);
        $this->assertInstanceOf(Process::class, $proc);
        $this->assertGreaterThan(0, $proc->pid());
        $proc->wait();
    }

    public function testSpawnsAndReportsExitCodeZero(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(['/bin/echo', 'hello'], null, true, false);
        $this->assertSame(0, $proc->wait());
        $this->assertSame(0, $proc->exitCode());
        $this->assertStringContainsString('hello', $proc->stdoutBytes());
    }

    public function testSpawnsAndReportsNonZeroExitCode(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(['/bin/sh', '-c', 'exit 42']);
        $this->assertSame(42, $proc->wait());
    }

    public function testNoCaptureStdoutBytesEmpty(): void
    {
        $this->requireRealBinaries();

        // captureStdout=false → stdout inherits parent STDOUT; nothing buffered.
        $proc = PosixProcess::spawn(['/bin/echo', 'hello'], null, false, false);
        $proc->wait();
        $this->assertSame('', $proc->stdoutBytes());
        $this->assertSame('', $proc->stderrBytes());
    }

    public function testCaptureStdoutAndStderrSeparately(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(
            ['/bin/sh', '-c', 'echo out; echo err >&2'],
            null,
            true,
            true,
        );
        $this->assertSame(0, $proc->wait());
        $this->assertStringContainsString('out', $proc->stdoutBytes());
        $this->assertStringContainsString('err', $proc->stderrBytes());
        $this->assertStringNotContainsString('err', $proc->stdoutBytes());
        $this->assertStringNotContainsString('out', $proc->stderrBytes());
    }

    public function testKillSigtermTerminatesLongRunningChild(): void
    {
        $this->requireRealBinaries();
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for signal tests.');
        }

        $proc     = PosixProcess::spawn(['/bin/sleep', '10']);
        $deadline = \microtime(true) + 2.0;
        $proc->kill(Process::SIGTERM);
        $proc->wait();
        $this->assertLessThan($deadline, \microtime(true), 'wait() after SIGTERM took longer than 2s');
        $this->assertTrue($proc->exited());
    }

    public function testKillSigkillTerminatesUncooperativeChild(): void
    {
        $this->requireRealBinaries();
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for signal tests.');
        }

        $proc     = PosixProcess::spawn(['/bin/sleep', '10']);
        $deadline = \microtime(true) + 2.0;
        $proc->kill(Process::SIGKILL);
        $proc->wait();
        $this->assertLessThan($deadline, \microtime(true), 'wait() after SIGKILL took longer than 2s');
        $this->assertTrue($proc->exited());
    }

    public function testExitedReturnsFalseWhileRunningTrueAfterExit(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(['/bin/sh', '-c', 'sleep 0.2']);
        $this->assertFalse($proc->exited(), 'exited() should be false immediately after spawn');
        $proc->wait();
        $this->assertTrue($proc->exited());
        $this->assertSame(0, $proc->exitCode());
    }

    public function testWaitIdempotentReturnsCachedExitCode(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(['/bin/sh', '-c', 'exit 5']);
        $this->assertSame(5, $proc->wait());
        $this->assertSame(5, $proc->wait());
        $this->assertSame(5, $proc->exitCode());
    }

    public function testNoStdinFromParent(): void
    {
        $this->requireRealBinaries();

        // Parent stdin is bound to /dev/null, so `read line` hits EOF
        // immediately and `$line` ends up empty.
        $proc = PosixProcess::spawn(
            ['/bin/sh', '-c', 'read line; echo "got: $line"'],
            null,
            true,
            false,
        );
        $proc->wait();
        $this->assertStringContainsString('got:', $proc->stdoutBytes());
    }

    public function testConstructorRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PosixProcess(123, 'not-a-resource');
    }

    public function testSpawnRejectsEmptyCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PosixProcess::spawn([]);
    }

    public function testPtyExceptionClassReachable(): void
    {
        // Sanity-check that the spawn failure path's exception type is wired up;
        // forcing a real proc_open() failure on Linux is unreliable because
        // execvp() failures still produce a valid parent-side resource (the
        // failure surfaces only in the child's exit code).
        $this->assertTrue(\class_exists(PtyException::class));
    }

    public function testSpawnInheritsCustomEnv(): void
    {
        $this->requireRealBinaries();

        $proc = PosixProcess::spawn(
            ['/bin/sh', '-c', 'echo "$MY_VAR"'],
            ['MY_VAR' => 'sweet'],
            true,
            false,
        );
        $this->assertSame(0, $proc->wait());
        $this->assertStringContainsString('sweet', $proc->stdoutBytes());
    }
}
