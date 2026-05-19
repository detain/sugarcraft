<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Libc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;

/**
 * Verifies the `openpty` FFI binding resolves and returns valid
 * master/slave file descriptors on platforms that provide it.
 */
final class OpenptyTest extends TestCase
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

    /**
     * Structural test: on Darwin, openpty must be declared in the cdef
     * block so any resolution failure surfaces at cdef-load time, not at
     * a runtime call site.
     */
    public function testCdefContainsOpenptyOnDarwin(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('openpty is Darwin-only in the default cdef.');
        }

        $this->assertStringContainsString('openpty(', Libc::cdef());
    }

    /**
     * Behavioural test (Darwin only): `openpty()` must return a pair of
     * valid non-negative file descriptors and the slave path must match
     * the platform layout.
     */
    public function testOpenptyReturnsValidMasterSlavePairOnDarwin(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('openpty() primary path is Darwin-only.');
        }

        $this->requirePtySyscalls();

        Libc::reset();
        $libc = Libc::lib();

        $masterFdPtr = $libc->new('int[1]');
        $slaveFdPtr = $libc->new('int[1]');

        $nameBuf = $libc->new('char[256]');

        $rc = $libc->openpty(
            \FFI::addr($masterFdPtr[0]),
            \FFI::addr($slaveFdPtr[0]),
            $nameBuf,
            null,
            null,
        );

        try {
            $this->assertSame(0, $rc, 'openpty() must return 0 on success');
            $this->assertGreaterThanOrEqual(0, $masterFdPtr[0], 'master fd must be non-negative');
            $this->assertGreaterThanOrEqual(0, $slaveFdPtr[0], 'slave fd must be non-negative');

            $slavePath = \FFI::string($nameBuf);
            $this->assertNotEmpty($slavePath);
            $this->assertMatchesRegularExpression(
                '#^/dev/ttys\d+$#',
                $slavePath,
                "Darwin slave path '{$slavePath}' must match /dev/ttysNNN pattern",
            );

            // Both fds must be open (distinct, non-negative).
            $this->assertNotSame($masterFdPtr[0], $slaveFdPtr[0], 'master and slave must be distinct fds');
        } finally {
            // Clean up both fds.
            if ($masterFdPtr[0] >= 0) {
                $libc->close($masterFdPtr[0]);
            }
            if ($slaveFdPtr[0] >= 0) {
                $libc->close($slaveFdPtr[0]);
            }
            Libc::reset();
        }
    }

    /**
     * Linux may have `openpty` in libutil rather than libc. This test
     * verifies the symbol is NOT in the default cdef (intentionally —
     * the quartet path is used on Linux, not openpty).
     */
    public function testOpenptyIsNotInDefaultLinuxCdef(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('This test is Linux-specific.');
        }

        // On Linux the cdef targets libc, which does NOT export openpty.
        // The symbol lives in libutil.so.1. cdef must NOT declare it
        // so that Linux FFI load succeeds without needing a separate load.
        $this->assertStringNotContainsString(
            'openpty(',
            Libc::cdef(),
            'openpty must not be in the default Linux libc cdef (it lives in libutil)',
        );
    }
}
