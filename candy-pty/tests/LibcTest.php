<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * Direct unit tests for Libc FFI binding class.
 *
 * These tests cover the static methods that wrap libc calls:
 * - lib()      : loads/caches the FFI handle
 * - reset()    : clears the cached FFI handle
 * - libraryPath(): resolves the libc shared-object path
 * - cdef()     : returns the C declaration block
 */
final class LibcTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────
    // lib()
    // ─────────────────────────────────────────────────────────────

    public function testLibLoadsFFIAndReturnsCachedInstance(): void
    {
        $this->requirePtySyscalls();

        // Reset to ensure we start clean.
        Libc::reset();

        $ffi1 = Libc::lib();
        $this->assertInstanceOf(\FFI::class, $ffi1);

        // Subsequent calls must return the same instance.
        $ffi2 = Libc::lib();
        $this->assertSame($ffi1, $ffi2, 'lib() must return the cached FFI instance');

        // Clean up for other tests.
        Libc::reset();
    }

    public function testLibThrowsPtyExceptionOnInvalidLibrary(): void
    {
        $previous = \getenv('SUGARCRAFT_LIBC');

        try {
            \putenv('SUGARCRAFT_LIBC=/nonexistent/libc.so.42');

            $this->expectException(PtyException::class);
            $this->expectExceptionMessageMatches('#Failed to load libc#');

            Libc::lib();
        } finally {
            Libc::reset();
            if ($previous === false) {
                \putenv('SUGARCRAFT_LIBC');
            } else {
                \putenv('SUGARCRAFT_LIBC=' . $previous);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // reset()
    // ─────────────────────────────────────────────────────────────

    public function testResetClearsCachedFFI(): void
    {
        $this->requirePtySyscalls();

        // Load and cache the FFI.
        $ffi1 = Libc::lib();
        $this->assertInstanceOf(\FFI::class, $ffi1);

        // Reset must clear the handle.
        Libc::reset();

        // A fresh call must return a new instance (not the same object).
        $ffi2 = Libc::lib();
        $this->assertInstanceOf(\FFI::class, $ffi2);
        $this->assertNotSame($ffi1, $ffi2, 'reset() must allow a new FFI instance to be created');

        // Clean up.
        Libc::reset();
    }

    public function testResetCanBeCalledMultipleTimes(): void
    {
        // Calling reset() when no FFI is loaded must not throw.
        Libc::reset();
        Libc::reset();
        Libc::reset(); // Must not raise.
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // libraryPath()
    // ─────────────────────────────────────────────────────────────

    public function testLibraryPathReturnsDefaultLinuxOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Only relevant on Linux.');
        }

        \putenv('SUGARCRAFT_LIBC'); // Clear any override.
        $this->assertSame(Libc::DEFAULT_LINUX, Libc::libraryPath());
    }

    public function testLibraryPathReturnsDefaultDarwinOnMacOS(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Only relevant on macOS.');
        }

        \putenv('SUGARCRAFT_LIBC'); // Clear any override.
        $this->assertSame(Libc::DEFAULT_DARWIN, Libc::libraryPath());
    }

    public function testLibraryPathHonoursEnvironmentOverride(): void
    {
        $previous = \getenv('SUGARCRAFT_LIBC');

        try {
            \putenv('SUGARCRAFT_LIBC=/my/custom/libc.so');
            $this->assertSame('/my/custom/libc.so', Libc::libraryPath());

            \putenv('SUGARCRAFT_LIBC=');
            $expected = PHP_OS_FAMILY === 'Darwin'
                ? Libc::DEFAULT_DARWIN
                : Libc::DEFAULT_LINUX;
            $this->assertSame($expected, Libc::libraryPath());
        } finally {
            if ($previous === false) {
                \putenv('SUGARCRAFT_LIBC');
            } else {
                \putenv('SUGARCRAFT_LIBC=' . $previous);
            }
        }
    }

    public function testLibraryPathTreatsEmptyStringAsNoOverride(): void
    {
        $previous = \getenv('SUGARCRAFT_LIBC');

        try {
            \putenv('SUGARCRAFT_LIBC=');
            $expected = PHP_OS_FAMILY === 'Darwin'
                ? Libc::DEFAULT_DARWIN
                : Libc::DEFAULT_LINUX;
            $this->assertSame($expected, Libc::libraryPath());
        } finally {
            if ($previous === false) {
                \putenv('SUGARCRAFT_LIBC');
            } else {
                \putenv('SUGARCRAFT_LIBC=' . $previous);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // cdef()
    // ─────────────────────────────────────────────────────────────

    public function testCdefReturnsNonEmptyString(): void
    {
        $cdef = Libc::cdef();
        $this->assertIsString($cdef);
        $this->assertNotEmpty($cdef);
    }

    public function testCdefContainsPosixOpenpt(): void
    {
        $this->assertStringContainsString('posix_openpt(', Libc::cdef());
    }

    public function testCdefContainsGrantpt(): void
    {
        $this->assertStringContainsString('grantpt(', Libc::cdef());
    }

    public function testCdefContainsUnlockpt(): void
    {
        $this->assertStringContainsString('unlockpt(', Libc::cdef());
    }

    public function testCdefContainsPtsnameR(): void
    {
        $this->assertStringContainsString('ptsname_r(', Libc::cdef());
    }

    public function testCdefContainsClose(): void
    {
        $this->assertStringContainsString('close(', Libc::cdef());
    }

    public function testCdefContainsIoctl(): void
    {
        $this->assertStringContainsString('ioctl(', Libc::cdef());
    }

    // ─────────────────────────────────────────────────────────────
    // Constructor is private (utility class)
    // ─────────────────────────────────────────────────────────────

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(Libc::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
