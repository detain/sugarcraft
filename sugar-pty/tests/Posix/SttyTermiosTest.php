<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\SttyTermios;

/**
 * Integration tests for SttyTermios against a real PTY.
 *
 * Exercises the full stty shell-out path: stty -g (save),
 * stty raw -echo (apply), stty <saved> (restore).
 * Verifies that raw mode actually suppresses echo and canonical line buffering.
 */
final class SttyTermiosTest extends TestCase
{
    private const O_RDWR = 0x0002;

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only.');
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
            $termios = new SttyTermios($slaveFd);

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
            $saved->restore();
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
            $termios = new SttyTermios($slaveFd);
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
            $termios = new SttyTermios($slaveFd);
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
            $termios = new SttyTermios($pipe);
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
            $termios = new SttyTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(SttyTermios::TCSADRAIN);

            $this->assertTrue(true, 'apply(TCSADRAIN) must not throw');
        } finally {
            $saved->restore();
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
            $termios = new SttyTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(SttyTermios::TCSAFLUSH);

            $this->assertTrue(true, 'apply(TCSAFLUSH) must not throw');
        } finally {
            $saved->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testRestoreWithoutSnapshotRefuses(): void
    {
        // The saved-mode string is the only thing restore() can write
        // back. A silent no-op on a handle that never captured one let
        // callers believe modes had been restored.
        $termios = new SttyTermios(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a captured snapshot');

        $termios->restore();
    }

    public function testFallbackReadsModeOfCloseOnExecMasterFd(): void
    {
        // Every master this lib mints carries FD_CLOEXEC. The retired
        // `stty -f /dev/fd/<n>` spelling named the descriptor in the
        // CHILD's table — where an exec-closed fd does not exist — so
        // the fallback was dead against precisely those handles. The
        // child-stdin handoff must read and restore a live cloexec
        // master's mode.
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();

        try {
            $termios = new SttyTermios($master->fd());
            $snapshot = $termios->current();
            $savedProp = new \ReflectionProperty(SttyTermios::class, 'savedMode');
            $this->assertNotSame('', $savedProp->getValue($snapshot),
                'stty -g must read a mode string from a cloexec master fd via the stdin handoff');
            $snapshot->restore();
        } finally {
            $master->close();
        }
    }
}
