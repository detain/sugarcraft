<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;

/**
 * Tests for the termios FFI declarations in Libc::cdef().
 *
 * These tests verify that the cdef parses and that the new termios
 * symbols resolve via FFI::cdef() on Linux and macOS.
 * No actual termios syscalls are performed.
 */
final class LibcTermiosTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
    }

    public function testCdefContainsTcgetattr(): void
    {
        $this->assertStringContainsString('tcgetattr(', Libc::cdef());
    }

    public function testCdefContainsTcsetattr(): void
    {
        $this->assertStringContainsString('tcsetattr(', Libc::cdef());
    }

    public function testCdefContainsCfmakeraw(): void
    {
        $this->assertStringContainsString('cfmakeraw(', Libc::cdef());
    }

    public function testCdefContainsCfgetospeed(): void
    {
        $this->assertStringContainsString('cfgetospeed(', Libc::cdef());
    }

    public function testCdefContainsCfsetospeed(): void
    {
        $this->assertStringContainsString('cfsetospeed(', Libc::cdef());
    }

    public function testCdefContainsCfgetispeed(): void
    {
        $this->assertStringContainsString('cfgetispeed(', Libc::cdef());
    }

    public function testCdefContainsCfsetispeed(): void
    {
        $this->assertStringContainsString('cfsetispeed(', Libc::cdef());
    }

    public function testCdefParsesWithoutThrowing(): void
    {
        $this->requirePtySyscalls();

        $ffi = \FFI::cdef(Libc::cdef(), Libc::libraryPath());
        $this->assertInstanceOf(\FFI::class, $ffi);
    }

    public function testTermiosSymbolsResolve(): void
    {
        $this->requirePtySyscalls();

        $ffi = \FFI::cdef(Libc::cdef(), Libc::libraryPath());

        $symbols = ['tcgetattr', 'tcsetattr', 'cfmakeraw', 'cfgetospeed', 'cfsetospeed', 'cfgetispeed', 'cfsetispeed'];
        foreach ($symbols as $symbol) {
            $this->assertTrue(
                \is_callable([$ffi, $symbol]),
                "Symbol {$symbol} must be resolvable via FFI"
            );
        }
    }
}
