<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\SignalForwarder;

/**
 * Extended SignalForwarder tests covering attachSigwinchToFd,
 * attachedSignals, and the dispatch() edge case.
 */
final class SignalForwarderExtendedTest extends TestCase
{
    private function requirePcntl(): void
    {
        if (!SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('ext-pcntl is required for SignalForwarder.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this host.');
        }
    }

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

    protected function tearDown(): void
    {
        SignalForwarder::reset();
    }

    // ─────────────────────────────────────────────────────────────
    // attachSigwinchToFd()
    // ─────────────────────────────────────────────────────────────

    public function testAttachSigwinchToFdReturnsFalseWhenPcntlMissing(): void
    {
        // If pcntl is missing, the method must return false cleanly.
        if (SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('Only meaningful when pcntl is NOT available.');
        }

        $ok = SignalForwarder::attachSigwinchToFd(
            0,
            static fn () => ['cols' => 80, 'rows' => 24],
        );
        $this->assertFalse($ok);
    }

    public function testAttachSigwinchToFdReturnsFalseWhenSigwinchUndefined(): void
    {
        $this->requirePcntl();

        if (\defined('SIGWINCH')) {
            $this->markTestSkipped('Only meaningful when SIGWINCH is NOT defined.');
        }

        $ok = SignalForwarder::attachSigwinchToFd(
            0,
            static fn () => ['cols' => 80, 'rows' => 24],
        );
        $this->assertFalse($ok);
    }

    public function testAttachSigwinchToFdWithRealFd(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $pty = Pty::open();
        try {
            $invoked = 0;
            $ok = SignalForwarder::attachSigwinchToFd(
                $pty->fd(),
                function () use (&$invoked): array {
                    $invoked++;
                    return ['cols' => 77, 'rows' => 22];
                },
                async: false,
            );

            $this->assertTrue($ok);

            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            $this->assertSame(1, $invoked, 'size provider should fire on SIGWINCH');
            $this->assertContains(SIGWINCH, SignalForwarder::attachedSignals());
        } finally {
            $pty->close();
        }
    }

    public function testAttachSigwinchToFdWithOnResizeCallback(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $pty = Pty::open();
        try {
            $resizeCalled = false;
            $ok = SignalForwarder::attachSigwinchToFd(
                $pty->fd(),
                static fn () => ['cols' => 99, 'rows' => 33],
                function (int $cols, int $rows) use (&$resizeCalled): void {
                    $resizeCalled = [$cols, $rows];
                },
                async: false,
            );

            $this->assertTrue($ok);

            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            $this->assertIsArray($resizeCalled);
            $this->assertSame([99, 33], $resizeCalled);
        } finally {
            $pty->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // attachedSignals()
    // ─────────────────────────────────────────────────────────────

    public function testAttachedSignalsReturnsEmptyArrayInitially(): void
    {
        SignalForwarder::reset();

        $signals = SignalForwarder::attachedSignals();
        $this->assertIsArray($signals);
        // After full reset, no signals should be attached.
        $this->assertSame([], $signals);
    }

    public function testAttachedSignalsContainsAttachedHandler(): void
    {
        $this->requirePcntl();

        if (!\defined('SIGCHLD')) {
            $this->markTestSkipped('SIGCHLD is not defined on this host.');
        }

        try {
            SignalForwarder::reset();
            $this->assertNotContains(SIGCHLD, SignalForwarder::attachedSignals());

            SignalForwarder::attachSigchld(static fn () => null, async: false);
            $this->assertContains(SIGCHLD, SignalForwarder::attachedSignals());
        } finally {
            SignalForwarder::reset();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // dispatch() without pcntl_signal_dispatch
    // ─────────────────────────────────────────────────────────────

    public function testDispatchIsSafeToCallWhenPcntlNotAvailable(): void
    {
        // Just verify it doesn't throw.
        SignalForwarder::dispatch();
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // asyncEnabled()
    // ─────────────────────────────────────────────────────────────

    public function testAsyncEnabledFalseBeforeAnyAttach(): void
    {
        SignalForwarder::reset();
        $this->assertFalse(SignalForwarder::asyncEnabled());
    }

    public function testAsyncEnabledTrueAfterAsyncAttach(): void
    {
        $this->requirePcntl();

        if (!\defined('SIGCHLD')) {
            $this->markTestSkipped('SIGCHLD is not defined on this host.');
        }

        try {
            SignalForwarder::reset();
            SignalForwarder::attachSigchld(static fn () => null, async: true);
            $this->assertTrue(SignalForwarder::asyncEnabled());
        } finally {
            SignalForwarder::reset();
        }
    }
}
