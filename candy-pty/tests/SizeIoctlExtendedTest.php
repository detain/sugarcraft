<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\SizeIoctl;

/**
 * Unit tests for SizeIoctl - pack() negative value guard,
 * emptyBuffer(), unpack(), Darwin stty fallback (when ioctl fails),
 * and the constants.
 */
final class SizeIoctlExtendedTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────
    // pack() edge cases
    // ─────────────────────────────────────────────────────────────

    public function testPackWithZeroValues(): void
    {
        $this->requirePtySyscalls();

        $ws = SizeIoctl::pack(0, 0, 0, 0);
        $this->assertInstanceOf(\FFI\CData::class, $ws);

        $unpacked = SizeIoctl::unpack($ws);
        $this->assertSame(0, $unpacked['rows']);
        $this->assertSame(0, $unpacked['cols']);
        $this->assertSame(0, $unpacked['xpix']);
        $this->assertSame(0, $unpacked['ypix']);
    }

    public function testPackWithTypicalValues(): void
    {
        $this->requirePtySyscalls();

        $ws = SizeIoctl::pack(24, 80, 0, 0);
        $unpacked = SizeIoctl::unpack($ws);

        $this->assertSame(24, $unpacked['rows']);
        $this->assertSame(80, $unpacked['cols']);
        $this->assertSame(0, $unpacked['xpix']);
        $this->assertSame(0, $unpacked['ypix']);
    }

    public function testPackRejectsNegativeRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('winsize fields must be non-negative');

        SizeIoctl::pack(-1, 80);
    }

    public function testPackRejectsNegativeCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('winsize fields must be non-negative');

        SizeIoctl::pack(24, -1);
    }

    public function testPackRejectsNegativeXpix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('winsize fields must be non-negative');

        SizeIoctl::pack(24, 80, -1, 0);
    }

    public function testPackRejectsNegativeYpix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('winsize fields must be non-negative');

        SizeIoctl::pack(24, 80, 0, -1);
    }

    // ─────────────────────────────────────────────────────────────
    // emptyBuffer()
    // ─────────────────────────────────────────────────────────────

    public function testEmptyBufferReturnsCData(): void
    {
        $this->requirePtySyscalls();

        $ws = SizeIoctl::emptyBuffer();
        $this->assertInstanceOf(\FFI\CData::class, $ws);
    }

    // ─────────────────────────────────────────────────────────────
    // unpack()
    // ─────────────────────────────────────────────────────────────

    public function testUnpackReturnsCorrectFields(): void
    {
        $this->requirePtySyscalls();

        $ws = SizeIoctl::pack(30, 120, 640, 480);
        $result = SizeIoctl::unpack($ws);

        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('cols', $result);
        $this->assertArrayHasKey('xpix', $result);
        $this->assertArrayHasKey('ypix', $result);

        $this->assertSame(30, $result['rows']);
        $this->assertSame(120, $result['cols']);
        $this->assertSame(640, $result['xpix']);
        $this->assertSame(480, $result['ypix']);
    }

    // ─────────────────────────────────────────────────────────────
    // setRequest() / getRequest()
    // ─────────────────────────────────────────────────────────────

    public function testSetRequestReturnsPlatformSpecificValue(): void
    {
        $req = SizeIoctl::setRequest();
        $this->assertIsInt($req);
        $this->assertGreaterThan(0, $req);
    }

    public function testGetRequestReturnsPlatformSpecificValue(): void
    {
        $req = SizeIoctl::getRequest();
        $this->assertIsInt($req);
        $this->assertGreaterThan(0, $req);
    }

    public function testSetRequestDiffersFromGetRequest(): void
    {
        // The set (write) and get (read) request codes must be distinct.
        $this->assertNotSame(SizeIoctl::setRequest(), SizeIoctl::getRequest());
    }

    // ─────────────────────────────────────────────────────────────
    // Constants
    // ─────────────────────────────────────────────────────────────

    public function testLinuxConstants(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux-specific constants.');
        }
        $this->assertSame(0x5414, SizeIoctl::LINUX_TIOCSWINSZ);
        $this->assertSame(0x5413, SizeIoctl::LINUX_TIOCGWINSZ);
    }

    public function testDarwinConstants(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Darwin-specific constants.');
        }
        $this->assertSame(0x80087467, SizeIoctl::DARWIN_TIOCSWINSZ);
        $this->assertSame(0x40087468, SizeIoctl::DARWIN_TIOCGWINSZ);
    }

    public function testWinsizeFieldsConstant(): void
    {
        $this->assertSame(4, SizeIoctl::WINSIZE_FIELDS);
    }

    // ─────────────────────────────────────────────────────────────
    // setSizeViaLibc() via PosixMasterPty resize (integration)
    // ─────────────────────────────────────────────────────────────

    public function testSetSizeViaLibcRoundTrip(): void
    {
        $this->requirePtySyscalls();
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi required for this test.');
        }

        $system = new \SugarCraft\Pty\Posix\PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $masterFd = $master->fd();

        try {
            // Resize to non-default values.
            $master->resize(100, 40);

            // Query back.
            $size = SizeIoctl::query($masterFd);
            $this->assertSame(100, $size['cols']);
            $this->assertSame(40, $size['rows']);
        } finally {
            $master->close();
        }
    }
}
