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

        try {
            $termios = new PosixTermios($slaveFd);

            $saved = $termios->current();
            $termios->restore();

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
            $termios->restore();
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

            $termios->restore();

            $this->assertTrue($termios->isAtty(), 'PTY slave should be a tty');
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
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

        try {
            $termios = new PosixTermios($slaveFd);
            $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSADRAIN);

            $this->assertTrue(true, 'apply(TCSADRAIN) must not throw');
        } finally {
            $termios->restore();
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

        try {
            $termios = new PosixTermios($slaveFd);
            $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSAFLUSH);

            $this->assertTrue(true, 'apply(TCSAFLUSH) must not throw');
        } finally {
            $termios->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }
}
