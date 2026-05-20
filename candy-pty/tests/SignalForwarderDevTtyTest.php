<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\SignalForwarder;

/**
 * Integration tests for SignalForwarder::attachSigwinchToFd().
 *
 * These tests require a real /dev/tty (not available in headless CI
 * containers) so they are skipped when the device is absent.
 */
final class SignalForwarderDevTtyTest extends TestCase
{
    private function requireDevTty(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\is_readable('/dev/tty') || !\is_writable('/dev/tty')) {
            $this->markTestSkipped('/dev/tty is not available on this host (headless CI environment).');
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
        if (\defined('SIGWINCH')) {
            SignalForwarder::reset(SIGWINCH);
        }
    }

    public function testAttachSigwinchToFdInstallsHandlerAndReportsSize(): void
    {
        $this->requireDevTty();
        $this->requirePcntl();

        // O_RDWR = 0x0002 — read/write access for /dev/tty
        $ttyFd = \SugarCraft\Pty\Libc::lib()->open('/dev/tty', 0x0002);
        if ($ttyFd < 0) {
            $this->markTestSkipped('Could not open /dev/tty.');
        }

        try {
            $invocations = 0;
            $reportedCols = null;
            $reportedRows = null;

            $ok = SignalForwarder::attachSigwinchToFd(
                $ttyFd,
                function () use (&$invocations): array {
                    $invocations++;
                    return ['cols' => 120, 'rows' => 40];
                },
                function (int $cols, int $rows) use (&$reportedCols, &$reportedRows): void {
                    $reportedCols = $cols;
                    $reportedRows = $rows;
                },
                async: false,
            );

            $this->assertTrue($ok, 'attachSigwinchToFd should return true');

            // Deliver SIGWINCH and dispatch the handler.
            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            $this->assertSame(1, $invocations, 'size provider should have been invoked exactly once');
            $this->assertSame(120, $reportedCols);
            $this->assertSame(40, $reportedRows);
        } finally {
            \SugarCraft\Pty\Libc::lib()->close($ttyFd);
        }
    }

    public function testAttachSigwinchToFdHandlesProviderException(): void
    {
        $this->requireDevTty();
        $this->requirePcntl();

        // O_RDWR = 0x0002
        $ttyFd = \SugarCraft\Pty\Libc::lib()->open('/dev/tty', 0x0002);
        if ($ttyFd < 0) {
            $this->markTestSkipped('Could not open /dev/tty.');
        }

        try {
            $ok = SignalForwarder::attachSigwinchToFd(
                $ttyFd,
                function (): array {
                    throw new \RuntimeException('size lookup failed');
                },
                null,
                async: false,
            );

            $this->assertTrue($ok);

            // Must NOT throw, must NOT crash dispatch.
            \posix_kill(\posix_getpid(), SIGWINCH);
            SignalForwarder::dispatch();

            // No exception propagated — reaching here means success.
            $this->assertTrue(true);
        } finally {
            \SugarCraft\Pty\Libc::lib()->close($ttyFd);
        }
    }

    public function testAttachSigwinchToFdReturnsFalseWhenPcntlUnavailable(): void
    {
        // This test does not need /dev/tty — we just verify the early return.
        if (SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('pcntl is available — this test is for the unavailable path.');
        }

        $ok = SignalForwarder::attachSigwinchToFd(
            0, // arbitrary fd — should not be opened
            fn (): array => ['cols' => 80, 'rows' => 24],
        );

        $this->assertFalse($ok);
    }
}
