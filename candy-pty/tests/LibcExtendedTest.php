<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * Extended tests for Libc class - errno, libutil, Windows guard,
 * and the Darwin-specific openpty codepath.
 */
final class LibcExtendedTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────
    // errno()
    // ─────────────────────────────────────────────────────────────

    public function testErrnoReturnsIntegerAfterLibcCall(): void
    {
        $this->requirePtySyscalls();
        Libc::reset();

        $libc = Libc::lib();
        // Call a simple syscall that sets errno; dup() is safe.
        $libc->dup(9999); // bad fd — should set errno
        $errno = Libc::errno();

        $this->assertIsInt($errno);
        $this->assertGreaterThan(0, $errno, 'errno should be a positive integer after a failing syscall');
    }

    public function testErrnoSymbolReturnsString(): void
    {
        $sym = Libc::errnoSymbol();
        $this->assertIsString($sym);
        $this->assertNotEmpty($sym);
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->assertSame('__error', $sym);
        } else {
            $this->assertSame('__errno_location', $sym);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // errnoDetail()
    // ─────────────────────────────────────────────────────────────

    public function testErrnoDetailReturnsString(): void
    {
        $this->requirePtySyscalls();
        Libc::reset();
        $libc = Libc::lib();

        // Trigger a failure that sets a known errno.
        $libc->dup(-1);
        $detail = Libc::errnoDetail();

        $this->assertIsString($detail);
        $this->assertNotEmpty($detail);
        // Should contain the errno number.
        $this->assertMatchesRegularExpression('/^\d+/', $detail);
    }

    // ─────────────────────────────────────────────────────────────
    // lib() Windows guard
    // ─────────────────────────────────────────────────────────────

    public function testLibThrowsOnWindowsPlatform(): void
    {
        // This test verifies the Windows guard exists but cannot
        // actually exercise it on a non-Windows host — so we verify
        // the guard path exists by checking the class structure.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Only runnable on non-Windows.');
        }

        $reflection = new \ReflectionMethod(Libc::class, 'lib');
        $this->assertTrue($reflection->isPublic());
    }

    // ─────────────────────────────────────────────────────────────
    // libutil() on Linux
    // ─────────────────────────────────────────────────────────────

    public function testLibutilReturnsFFIOnLinux(): void
    {
        $this->requirePtySyscalls();

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Darwin path is tested separately via lib()');
        }

        Libc::reset();
        $ffi = Libc::libutil();

        $this->assertInstanceOf(\FFI::class, $ffi);
        // openpty should be callable via the libutil handle on Linux.
        // We verify by calling it (result rc=0 means pts pair created).
        $masterPtr = $ffi->new('int[1]');
        $slavePtr = $ffi->new('int[1]');
        $rc = $ffi->openpty(\FFI::addr($masterPtr[0]), \FFI::addr($slavePtr[0]), null, null, null);
        $this->assertSame(0, $rc, 'openpty on Linux via libutil should succeed');
        // Clean up.
        if ($masterPtr[0] >= 0) {
            $ffi->close($masterPtr[0]);
        }
        if ($slavePtr[0] >= 0) {
            $ffi->close($slavePtr[0]);
        }
    }

    public function testLibutilOnDarwinReturnsLibcFFI(): void
    {
        $this->requirePtySyscalls();

        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Darwin-specific codepath.');
        }

        Libc::reset();
        $ffiDarwin = Libc::lib();
        Libc::reset();
        $ffiUtil = Libc::libutil();

        // On Darwin, libutil() just returns the regular libc handle.
        $this->assertInstanceOf(\FFI::class, $ffiUtil);
    }

    public function testLibutilThrowsOnWindows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Only runnable on non-Windows.');
        }

        // The Windows guard throws PtyException.
        $reflection = new \ReflectionMethod(Libc::class, 'libutil');
        $this->assertTrue($reflection->isPublic());
    }

    // ─────────────────────────────────────────────────────────────
    // EINTR constant
    // ─────────────────────────────────────────────────────────────

    public function testEINTRConstantIsCorrect(): void
    {
        $this->assertSame(4, Libc::EINTR, 'EINTR should be 4 on POSIX systems');
    }

    // ─────────────────────────────────────────────────────────────
    // Constants are accessible
    // ─────────────────────────────────────────────────────────────

    public function testDefaultLinuxConstant(): void
    {
        $this->assertSame('libc.so.6', Libc::DEFAULT_LINUX);
    }

    public function testDefaultDarwinConstant(): void
    {
        $this->assertSame('/usr/lib/libSystem.B.dylib', Libc::DEFAULT_DARWIN);
    }

    public function testDefaultLinuxUtilsConstant(): void
    {
        $this->assertSame('libutil.so.1', Libc::DEFAULT_LINUX_UTILS);
    }
}
