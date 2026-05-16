<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\SizeIoctl;

/**
 * Integration tests for SizeIoctl::query().
 *
 * Opens a real PTY, resizes it to a known geometry, then
 * queries the size via SizeIoctl::query() and asserts correctness.
 */
final class SizeIoctlQueryTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testQueryReturnsCorrectSizeAfterResize(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $masterFd = $master->fd();

        try {
            $master->resize(132, 40);

            $size = SizeIoctl::query($masterFd);

            $this->assertSame(132, $size['cols'], 'cols should be 132');
            $this->assertSame(40, $size['rows'], 'rows should be 40');
        } finally {
            $master->close();
        }
    }

    public function testQueryReturnsDefaultSize(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $masterFd = $master->fd();

        try {
            $size = SizeIoctl::query($masterFd);

            $this->assertArrayHasKey('cols', $size);
            $this->assertArrayHasKey('rows', $size);
            $this->assertArrayHasKey('xpix', $size);
            $this->assertArrayHasKey('ypix', $size);
            $this->assertIsInt($size['cols']);
            $this->assertIsInt($size['rows']);
            $this->assertGreaterThanOrEqual(0, $size['cols']);
            $this->assertGreaterThanOrEqual(0, $size['rows']);
        } finally {
            $master->close();
        }
    }

    public function testQueryThrowsOnNonTtyFd(): void
    {
        $libc = \SugarCraft\Pty\Libc::lib();
        $pipeFd = $libc->open('/dev/null', 0x0002);

        if ($pipeFd < 0) {
            $this->markTestSkipped('Could not open /dev/null');
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('non-tty');
            SizeIoctl::query($pipeFd);
        } finally {
            $libc->close($pipeFd);
        }
    }
}
