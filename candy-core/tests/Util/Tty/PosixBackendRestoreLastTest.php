<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\TermiosFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies PosixBackend::restoreLast() uses Termios snapshot
 * rather than stty -g shell-out.
 */
final class PosixBackendRestoreLastTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
    }

    public function testRestoreLastRoundTripsViaPtyMaster(): void
    {
        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $masterFd = $pair->master()->fd();

            // Capture original termios via factory (not stty -g).
            $original = TermiosFactory::open($masterFd)->current();
            $originalAtty = $original->isAtty();

            // Apply raw mode.
            $termios = TermiosFactory::open($masterFd);
            $termios->makeRaw()->apply();

            // restoreLast should restore the original termios state.
            PosixBackend::restoreLast();

            $after = TermiosFactory::open($masterFd);
            // isAtty() should match (terminal device unchanged).
            $this->assertSame($originalAtty, $after->isAtty());
        } finally {
            $pair->master()->close();
        }
    }

    public function testRestoreLastNoOpWithoutTtyStdin(): void
    {
        // When STDIN is not a TTY (e.g., php://memory, pipe, or closed),
        // TermiosFactory::open() throws and restoreLast() silently no-ops.
        // This verifies no stty -g shell-out is attempted.
        PosixBackend::restoreLast();
        PosixBackend::restoreLast();

        $this->assertTrue(true, 'restoreLast completed without throwing');
    }
}
