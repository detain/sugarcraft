<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\SignalForwarder;

final class SignalForwarderTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    private function requirePcntl(): void
    {
        if (!SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('ext-pcntl is required for SignalForwarder.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this host.');
        }
    }

    protected function tearDown(): void
    {
        // Guard against handler bleed across tests — restore default
        // dispositions for every signal we touch.
        if (\defined('SIGWINCH')) {
            SignalForwarder::reset(SIGWINCH);
        }
        if (\defined('SIGCHLD')) {
            SignalForwarder::reset(SIGCHLD);
        }
        if (\defined('SIGUSR1')) {
            SignalForwarder::reset(SIGUSR1);
        }
    }

    public function testPcntlReadyReflectsExtensionAvailability(): void
    {
        $this->assertSame(
            \function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch'),
            SignalForwarder::pcntlReady(),
        );
    }

    public function testAttachSigwinchInstallsHandlerAndReturnsTrue(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $pty = Pty::open();
        try {
            $invocations = 0;
            $ok = SignalForwarder::attachSigwinch(
                $pty,
                function () use (&$invocations): array {
                    $invocations++;
                    return ['cols' => 100, 'rows' => 30];
                },
                async: false,  // dispatch sync so we can drive signal delivery deterministically
            );

            $this->assertTrue($ok);

            // Deliver SIGWINCH to ourselves, dispatch the queued
            // handler, then assert the kernel-stored winsize matches
            // what the size provider returned.
            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            $this->assertSame(1, $invocations, 'size provider should have been invoked exactly once');
            $size = $pty->size();
            $this->assertSame(100, $size['cols']);
            $this->assertSame(30,  $size['rows']);
        } finally {
            $pty->close();
        }
    }

    public function testSigwinchHandlerNoOpsAfterPtyClose(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $pty = Pty::open();
        $invocations = 0;
        SignalForwarder::attachSigwinch(
            $pty,
            function () use (&$invocations): array {
                $invocations++;
                return ['cols' => 90, 'rows' => 25];
            },
            async: false,
        );

        $pty->close();

        // Once the Pty is closed, SIGWINCH receipt must NOT call the
        // size provider — the handler short-circuits via isClosed().
        \posix_kill(\posix_getpid(), SIGWINCH);
        SignalForwarder::dispatch();

        $this->assertSame(0, $invocations, 'size provider must not run after close()');
    }

    public function testSigwinchHandlerSwallowsProviderExceptions(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $pty = Pty::open();
        try {
            SignalForwarder::attachSigwinch(
                $pty,
                function (): array {
                    throw new \RuntimeException('size lookup blew up');
                },
                async: false,
            );

            // Must NOT throw, must NOT crash dispatch.
            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            // Pty still operational after the swallowed throw.
            $this->assertFalse($pty->isClosed());
        } finally {
            $pty->close();
        }
    }

    public function testAttachSigchldInstallsHandler(): void
    {
        $this->requirePcntl();
        if (!\defined('SIGCHLD')) {
            $this->markTestSkipped('SIGCHLD is not defined on this host.');
        }

        $reaped = false;
        $ok = SignalForwarder::attachSigchld(
            function () use (&$reaped): void {
                $reaped = true;
            },
            async: false,
        );

        $this->assertTrue($ok);

        \posix_kill(\posix_getpid(), SIGCHLD);
        SignalForwarder::dispatch();

        $this->assertTrue($reaped, 'reaper callback should run on SIGCHLD');
    }

    public function testReadSurvivesEintrFromSigwinch(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH not available.');
        }

        // Schedule a SIGWINCH for ~30 ms from now. Pty::read() is
        // mid-stream_select; the signal interrupts it (EINTR) and the
        // read loop dispatches pending pcntl handlers and re-arms the
        // deadline. The full read should still time out at ~80 ms,
        // not abort early on the EINTR.
        \pcntl_async_signals(true);
        $invocations = 0;

        $pty = Pty::open();
        try {
            SignalForwarder::attachSigwinch(
                $pty,
                function () use (&$invocations): array {
                    $invocations++;
                    return ['cols' => 110, 'rows' => 35];
                },
            );

            // Fork an alarm via pcntl_alarm — but pcntl_alarm sends
            // SIGALRM, not SIGWINCH. Easier to schedule the kill via
            // a tick-based registered shutdown; simpler still: use a
            // tiny child process to deliver the SIGWINCH.
            $pid = \posix_getpid();
            $alarmChild = \proc_open(
                ['/bin/sh', '-c', "sleep 0.03 && kill -WINCH {$pid}"],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($alarmChild);

            $start = \microtime(true);
            $bytes = $pty->read(1024, 0.08);
            $elapsed = \microtime(true) - $start;

            // Reap the alarm helper — its job is done.
            foreach ($pipes as $p) {
                if (\is_resource($p)) {
                    \fclose($p);
                }
            }
            \proc_close($alarmChild);

            $this->assertNull($bytes, 'read must still time out cleanly after EINTR retry');
            $this->assertGreaterThanOrEqual(0.07, $elapsed, "read returned too fast — EINTR likely aborted the wait (elapsed: {$elapsed}s)");
            $this->assertLessThan(1.0, $elapsed, 'read overshot timeout wildly');
            $this->assertGreaterThanOrEqual(1, $invocations, 'SIGWINCH handler should have run during the read');
        } finally {
            $pty->close();
        }
    }

    public function testChildSeesResizeAfterSigwinch(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();
        if (!\is_executable('/usr/bin/tput') && !\is_executable('/bin/tput')) {
            $this->markTestSkipped('tput not available.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH not available.');
        }

        $pty = Pty::open();
        $tmp = \tempnam(\sys_get_temp_dir(), 'candy-pty-sigwinch-');
        $this->assertNotFalse($tmp);

        try {
            SignalForwarder::attachSigwinch(
                $pty,
                fn (): array => ['cols' => 142, 'rows' => 47],
                async: false,
            );

            // Spawn a child that sleeps long enough for us to deliver
            // the SIGWINCH AND for the dispatch to land before tput
            // queries TIOCGWINSZ on the slave PTY.
            $child = $pty->spawn(
                ['/bin/sh', '-c', "sleep 0.05; tput cols > {$tmp}; tput lines >> {$tmp}"],
                ['TERM' => 'xterm-256color', 'PATH' => '/usr/bin:/bin'],
            );

            // Deliver + dispatch SIGWINCH while the child is in its
            // sleep — the resize lands before tput runs.
            \usleep(10_000);
            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            $exit = $child->wait();
            $this->assertSame(0, $exit);

            $out = (string) \file_get_contents($tmp);
            $lines = \array_values(\array_filter(\explode("\n", \trim($out)), 'is_numeric'));
            $this->assertCount(2, $lines, "expected two numeric lines, got: " . \var_export($out, true));
            $this->assertSame('142', $lines[0]);
            $this->assertSame('47',  $lines[1]);
        } finally {
            if (\file_exists($tmp)) {
                \unlink($tmp);
            }
            $pty->close();
        }
    }
}
