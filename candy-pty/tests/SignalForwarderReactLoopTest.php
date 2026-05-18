<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\SignalForwarder;

/**
 * Confirms `SignalForwarder::attachSigwinch` does not double-handle the
 * signal when invoked from inside a ReactPHP event loop.
 *
 * Risk #3 of the original P6 (PTY) plan: ReactPHP's loop installs its
 * own signal handling on some backends (ext-event, ext-libev, ext-uv);
 * naive `pcntl_signal` calls can be silently overwritten or fire twice
 * depending on signal-dispatch ordering. The plan called for explicit
 * coverage and never landed it. This test fills that gap.
 *
 * Mirrors the watchdog convention from PtyPoolReactLoopTest — the test
 * is hard-bounded by the loop's own timer cap so CI cannot hang.
 *
 * @see plans/leftover/phase-01-pty-quickwins/step-12-signal-forwarder-tests.md
 */
final class SignalForwarderReactLoopTest extends TestCase
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
    }

    private function requirePcntlAndReact(): void
    {
        if (!SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('ext-pcntl is required for SignalForwarder.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this host.');
        }
        if (!\class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if (\defined('SIGWINCH')) {
            SignalForwarder::reset(SIGWINCH);
        }
    }

    public function testAttachSigwinchFiresExactlyOnceInsideReactLoop(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntlAndReact();

        $loop = \React\EventLoop\Loop::get();
        $pty = Pty::open();
        $invocations = 0;

        try {
            // async:true so dispatch follows the same path the loop would
            // see in a long-lived program. If the loop's own signal layer
            // double-installed, we'd see >1 here.
            $ok = SignalForwarder::attachSigwinch(
                $pty,
                function () use (&$invocations): array {
                    $invocations++;
                    return ['cols' => 120, 'rows' => 40];
                },
                async: true,
            );
            $this->assertTrue($ok);

            // Send SIGWINCH to ourselves once.
            \posix_kill(\posix_getpid(), SIGWINCH);

            // Run the loop briefly so async signal dispatch + any
            // queued futureTick callbacks get a chance to fire. The
            // exact timer is the budget; nothing in the test schedules
            // its own work past this.
            $loop->addTimer(0.05, function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            $this->assertSame(
                1,
                $invocations,
                'attachSigwinch handler fired ' . $invocations . ' times — expected exactly 1. '
                . 'ReactPHP loop may have installed a competing pcntl_signal layer.',
            );
        } finally {
            $pty->close();
        }
    }
}
