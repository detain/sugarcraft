<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\ChildPollTrait;

/**
 * Verifies that {@see ChildPollTrait::tryWaitpid()} achieves sub-2ms process-exit
 * detection via the waitpid() FFI call, compared to the 10ms proc_get_status
 * polling baseline.
 *
 * Uses the same {@see ChildPollTraitStub} from ChildPollTraitTest as the
 * test subject.
 */
final class ChildPollWaitpidTest extends TestCase
{
    private function requireSh(): void
    {
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required to exercise the trait.');
        }
    }

    /**
     * @return array{0: resource, 1: int} [proc, pid]
     */
    private function spawn(string $script): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = \proc_open(['/bin/sh', '-c', $script], $descriptors, $pipes);
        if (!\is_resource($proc)) {
            $this->fail('proc_open failed');
        }
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }
        $status = \proc_get_status($proc);
        return [$proc, (int) $status['pid']];
    }

    /**
     * Helper: a trait-based stub that exposes tryWaitpid for direct testing.
     *
     * We cannot call tryWaitpid directly because it's a private method,
     * so we exercise it through exited() which is the public API that
     * calls tryWaitpid as its fast path.
     */
    private function makeStub(int $pid, $process): ChildPollTraitStub
    {
        return new ChildPollTraitStub($pid, $process);
    }

    public function testWaitpidFastPathDetectsExitUnder2ms(): void
    {
        $this->requireSh();

        // Spawn a process that exits immediately.
        [$proc, $pid] = $this->spawn('exit 0');
        $stub = $this->makeStub($pid, $proc);

        // Give the child a moment to exit naturally (shell exec + exit syscall).
        // Without this, the child is still running when we first call exited().
        \usleep(5_000);

        $start = \hrtime(true);

        // exited() calls tryWaitpid() as its fast path.
        $exited = $stub->exited();

        $elapsedNs = \hrtime(true) - $start;
        $elapsedMs = $elapsedNs / 1_000_000;

        $this->assertTrue($exited, 'process should be detected as exited');
        $this->assertLessThan(
            2.0,
            $elapsedMs,
            "waitpid fast-path detection should be <2ms, got {$elapsedMs}ms"
        );

        $stub->wait();
    }

    public function testWaitpidCapturesCorrectExitCode(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 42');
        $stub = $this->makeStub($pid, $proc);

        // Force waitpid to run via wait().
        $exitCode = $stub->wait();

        $this->assertSame(42, $exitCode, 'waitpid should return the correct exit code');
    }

    public function testWaitpidReturnsNullWhenChildStillRunning(): void
    {
        $this->requireSh();

        // Spawn a long-running process.
        [$proc, $pid] = $this->spawn('sleep 10');
        $stub = $this->makeStub($pid, $proc);

        // exited() should return false (null from tryWaitpid triggers fallback).
        $this->assertFalse($stub->exited());
        $this->assertNull($stub->exitCode());

        // Clean up.
        \proc_close($proc);
    }

    public function testWaitpidVsProcGetstatusTiming(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 0');
        $stub = $this->makeStub($pid, $proc);

        // Measure time for exited() which uses waitpid fast path.
        $startWaitpid = \hrtime(true);
        $stub->exited();
        $waitpidElapsedNs = \hrtime(true) - $startWaitpid;

        // Close and re-spawn to measure proc_get_status baseline.
        \proc_close($proc);
        [$proc2, $pid2] = $this->spawn('exit 0');
        $stub2 = $this->makeStub($pid2, $proc2);

        // Repeatedly call proc_get_status until child exits or timeout.
        $startPgs = \hrtime(true);
        $deadline = $startPgs + (10 * 1_000_000); // 10ms timeout
        while (true) {
            $status = \proc_get_status($proc2);
            if (($status['running'] ?? true) === false) {
                break;
            }
            if (\hrtime(true) > $deadline) {
                break;
            }
            \usleep(1_000); // 1ms polling interval
        }
        $pgsElapsedNs = \hrtime(true) - $startPgs;

        $waitpidMs = $waitpidElapsedNs / 1_000_000;
        $pgsMs = $pgsElapsedNs / 1_000_000;

        // waitpid should be faster than proc_get_status polling.
        $this->assertLessThan(
            $pgsMs,
            $waitpidMs + 1.0, // +1ms tolerance
            "waitpid ({$waitpidMs}ms) should be comparable to or faster than proc_get_status ({$pgsMs}ms)"
        );

        $stub2->wait();
    }

    public function testWaitWithWaitpidFastPath(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 5');
        $stub = $this->makeStub($pid, $proc);

        // Give the child a moment to exit naturally.
        \usleep(5_000);

        $start = \hrtime(true);
        $exitCode = $stub->wait();
        $elapsedNs = \hrtime(true) - $start;
        $elapsedMs = $elapsedNs / 1_000_000;

        $this->assertSame(5, $exitCode);
        $this->assertLessThan(
            2.0,
            $elapsedMs,
            "wait() with waitpid fast path should be <2ms for immediate-exit processes, got {$elapsedMs}ms"
        );
    }
}
