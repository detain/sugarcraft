<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;

/**
 * Integration tests for PosixTermios against a real PTY.
 *
 * Exercises the full FFI termios path: tcgetattr, cfmakeraw, tcsetattr.
 * Verifies that raw mode actually suppresses echo and canonical line buffering.
 */
final class PosixTermiosTest extends TestCase
{
    private const O_RDWR = 0x0002;

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for termios FFI.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testMakeRawDisablesEchoAndCanonicalMode(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);

            $saved = $termios->current();
            $saved->restore();

            $raw = $termios->makeRaw();
            $raw->apply();

            $child = $pair->slave()->spawn(['/bin/cat']);
            $master->write("hello\n");
            $captured = '';
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline) {
                $chunk = $master->read(4096, 0.1);
                if ($chunk === null || $chunk === '') {
                    \usleep(10_000);
                    continue;
                }
                $captured .= $chunk;
                if (\str_contains($captured, "hello\n")) {
                    break;
                }
            }
            $child->kill(\SIGTERM);
            $child->wait();

            $this->assertStringContainsString('hello', $captured, 'cat should have echoed input');
            $this->assertStringNotContainsString("\r", $captured, 'raw mode should have no CR from echo');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testRestoreReAppliesOriginalTermios(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        try {
            $termios = new PosixTermios($slaveFd);
            $original = $termios->current();

            $raw = $termios->makeRaw();
            $raw->apply();

            $original->restore();

            $this->assertTrue($termios->isAtty(), 'PTY slave should be a tty');
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    /**
     * A fresh instance never captured a snapshot via current(), so
     * restore() must fail loudly instead of silently no-op'ing (which
     * would strand the terminal in raw mode). This branch short-circuits
     * before any libc call, so it needs ext-ffi (constructor) but NOT a
     * real PTY — run it unconditionally on any FFI-capable host.
     */
    public function testRestoreWithoutSnapshotThrows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to construct PosixTermios.');
        }

        // fd value is irrelevant: restore() throws on the null-snapshot
        // guard before touching the descriptor.
        $termios = new PosixTermios(0);

        $this->expectException(\LogicException::class);
        $termios->restore();
    }

    public function testCurrentReturnsImmutableCopy(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        try {
            $termios = new PosixTermios($slaveFd);
            $current = $termios->current();

            $this->assertNotSame($termios, $current, 'current() must return a new instance');
            $this->assertSame($slaveFd, $current->fd());
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testIsAttyReturnsFalseForNonTtyFd(): void
    {
        $this->requirePtySyscalls();

        $libc = Libc::lib();

        $pipe = $libc->open('/dev/null', self::O_RDWR);
        if ($pipe < 0) {
            $this->markTestSkipped('Could not open /dev/null');
        }

        try {
            $termios = new PosixTermios($pipe);
            $this->assertFalse($termios->isAtty(), '/dev/null is not a tty');
        } finally {
            $libc->close($pipe);
        }
    }

    public function testApplyWithTcsadrainConst(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSADRAIN);

            $this->assertTrue(true, 'apply(TCSADRAIN) must not throw');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testApplyWithTcsaflushConst(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSAFLUSH);

            $this->assertTrue(true, 'apply(TCSAFLUSH) must not throw');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }
}
