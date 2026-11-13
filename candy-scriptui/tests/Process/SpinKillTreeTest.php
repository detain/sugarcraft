<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\ScriptUi\Process\RealProcess;

/**
 * scriptui-04 (candy-scriptui-006, HIGH): proc_open leaves the child in
 * OUR process group, so timeout/Esc/signal cancels signalled only the
 * direct child — `spin --timeout 1 -- sh -c 'sleep 27 & sleep 27'`
 * returned 124 with both grandchildren surviving as orphans.
 *
 * The cure is by construction: spawn behind a located detach wrapper
 * (util-linux `setsid -w --`, else the PHP posix_setsid+pcntl_exec
 * helper arm — the shape candy-code's ProcessContainment proved), which
 * makes the tracked pid LEAD its own group; terminate()/close() then
 * signal -pid, the whole tree, and close() sweeps until the kernel
 * answers ESRCH for the group.
 */
final class SpinKillTreeTest extends TestCase
{
    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('process-group children are a POSIX concept (Windows arms the scriptui-08 refusal).');
        }
    }

    /**
     * The crown: two grandchildren backgrounded under sh must BOTH die
     * when the handle is terminated + closed — the exact orphan repro
     * from the finding, minus the spinner noise.
     */
    public function testTerminateKillsTheWholeTreeNotJustTheDirectChild(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'sleep 21111 & echo $!; sleep 22222 & echo $!; wait'],
            captureStdout: true,
        );

        if (!$proc->groupManaged()) {
            $proc->terminate();
            $proc->close();
            $this->markTestSkipped('host offers no detach wrapper (no setsid(1), no posix+pcntl helper) — group kill cannot be claimed.');
        }

        // Wait for both pid lines to arrive through the capture pipe.
        $deadline = \microtime(true) + 5.0;
        $lines    = [];
        while (\microtime(true) < $deadline) {
            $lines = \array_filter(\explode("\n", \trim($proc->stdout())), 'strlen');
            if (\count($lines) >= 2) {
                break;
            }
            \usleep(20_000);
        }
        $this->assertGreaterThanOrEqual(2, \count($lines), 'both grandchildren must report their pids');
        [$bgOne, $bgTwo] = [(int) $lines[0], (int) $lines[1]];
        $this->assertGreaterThan(0, $bgOne);
        $this->assertGreaterThan(0, $bgTwo);

        $code = 0;
        try {
            $proc->terminate();          // SIGTERM -> the GROUP
            $code = $proc->close();      // grace + SIGKILL escalation + sweep
        } finally {
            // Belt: guarantee no sleep survives the test even on assertion failure.
            Libc::kill($bgOne, \defined('SIGKILL') ? \SIGKILL : 9);
            Libc::kill($bgTwo, \defined('SIGKILL') ? \SIGKILL : 9);
        }

        $this->assertTrue(\in_array($code, [-1, 143, 137], true), "wrapper death code unexpected: {$code}");
        $this->assertProcessGone($bgOne);
        $this->assertProcessGone($bgTwo, 'the second backgrounded sleep must die with its tree (orphan repro)');
    }

    /**
     * The detach wrapper must be TRANSPARENT to the existing contract:
     * exit status still forwards (`-w`), captured bytes still arrive —
     * the pinned shapes every spin lifecycle test depends on.
     */
    public function testWrapperForwardsExitStatusAndCapture(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'printf captured; exit 7'], captureStdout: true);
        $this->assertSame(7, $proc->wait(), 'setsid -w must forward the COMMAND status, never the wrapper verdict');
        $this->assertSame('captured', $proc->stdoutBytes());
        $this->assertSame(7, $proc->close());
    }

    /** A plain successful run under the wrapper still reaps to 0. */
    public function testWrapperPreservesCleanExit(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'true']);
        $this->assertSame(0, $proc->wait());
        $this->assertSame(0, $proc->close());
    }

    /** Group signalling must never target OUR OWN group: an unwrapped
     *  spawn (helper forced by direct construction is impossible) would
     *  share this pgid — pin that the wrapper actually relocated us. */
    public function testWrapperArmsAGroupDistinctFromTheRunner(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'echo $$; sleep 30'], captureStdout: true);
        if (!$proc->groupManaged()) {
            $proc->terminate();
            $proc->close();
            $this->markTestSkipped('no detach wrapper on this host — nothing relocated to compare.');
        }
        if (!\function_exists('posix_getpgid')) {
            $proc->terminate();
            $proc->close();
            $this->markTestSkipped('ext-posix absent — cannot read pgids to compare.');
        }
        $deadline = \microtime(true) + 5.0;
        $childPid = 0;
        while (\microtime(true) < $deadline) {
            $out = \trim($proc->stdout());
            if ($out !== '') {
                $childPid = (int) $out;
                break;
            }
            \usleep(20_000);
        }
        try {
            $this->assertGreaterThan(0, $childPid, 'sh must report its pid');
            // sh's pgid equals the tracked wrapper pid, NOT this runner's.
            $childPgid = (int) \posix_getpgid($childPid);
            $this->assertSame($proc->pid(), $childPgid, 'the group must be the wrapper, not the candy-scriptui process');
            $this->assertNotSame(
                (int) \posix_getpgid(\getmypid()),
                $childPgid,
                'cancel signals must be structurally incapable of reaching this process',
            );
        } finally {
            $proc->terminate();
            $proc->close();
        }
    }

    private function assertProcessGone(int $pid, string $why = ''): void
    {
        $deadline = \microtime(true) + 5.0;
        while (Libc::kill($pid, 0) && \microtime(true) < $deadline) {
            \usleep(20_000); // give reparented orphans a beat for init to reap zombies
        }
        $this->assertFalse(Libc::kill($pid, 0), $why !== '' ? $why : "pid {$pid} survived the tree kill");
    }
}
