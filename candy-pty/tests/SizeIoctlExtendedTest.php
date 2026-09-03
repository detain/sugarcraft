<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\SizeIoctl;

/**
 * Unit tests for SizeIoctl - pack() negative value guard,
 * emptyBuffer(), unpack(), the Darwin stty(1) fallbacks for SET and
 * GET (each when the ioctl fails), the Linux ioctl fast path of
 * getSizeViaLibc(), the BSD stty-reading parser pin, and the
 * constants.
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

    // ─────────────────────────────────────────────────────────────
    // getSizeViaLibc() — Linux ioctl fast path (the Darwin stty(1)
    // fallback must NEVER engage there; run 33796495350 / job
    // 100785492531 measured TIOCGWINSZ rc=-1 straight through the
    // fixed-arg cdef on arm64, which is why the fallback exists — see
    // the SizeIoctl::getSizeViaLibc() doc-block)
    // ─────────────────────────────────────────────────────────────

    public function testGetSizeViaLibcReadsBackTheAppliedGeometryThroughIoctl(): void
    {
        $this->requirePtySyscalls();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Pins the Linux fast path: the ioctl alone must answer, no fallback.');
        }

        $system = new \SugarCraft\Pty\Posix\PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();

        try {
            $master->resize(100, 40);

            $libc = \SugarCraft\Pty\Libc::lib();
            $ws = SizeIoctl::emptyBuffer();
            $rc = SizeIoctl::getSizeViaLibc($libc, $master->fd(), $ws);

            $this->assertSame(0, $rc, 'the Linux ioctl must answer directly');
            $size = SizeIoctl::unpack($ws);
            $this->assertSame(100, $size['cols']);
            $this->assertSame(40, $size['rows']);
        } finally {
            $master->close();
        }
    }

    public function testGetSizeViaLibcSurfacesTheIoctlRcForANonTtyWithoutTouchingTheBuffer(): void
    {
        $this->requirePtySyscalls();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Pins the Linux failure path: non-tty returns the ioctl rc, no fabricated 0.');
        }

        $libc = \SugarCraft\Pty\Libc::lib();
        $fd = $libc->open('/dev/null', 0x0002);
        if ($fd < 0) {
            $this->markTestSkipped('Could not open /dev/null');
        }

        try {
            $ws = SizeIoctl::emptyBuffer();
            $rc = SizeIoctl::getSizeViaLibc($libc, $fd, $ws);

            $this->assertNotSame(0, $rc, 'TIOCGWINSZ on /dev/null must fail, not be laundered into a success');
            $size = SizeIoctl::unpack($ws);
            $this->assertSame(['rows' => 0, 'cols' => 0, 'xpix' => 0, 'ypix' => 0], $size);
        } finally {
            $libc->close($fd);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // parseSttySize() — the Darwin fallback's reader, pinned on any
    // host so the next macos-15 run cannot be the first execution of
    // the parser.
    // ─────────────────────────────────────────────────────────────

    public function testParseSttySizeReadsTheBsdTranscriptShape(): void
    {
        // Shape MEASURED from macOS/BSD stty -a: geometry first, BSD
        // word order ("<n> rows" / "<n> columns" — GNU prints the
        // reverse, and a GNU reading must NOT parse; see the sibling
        // test). A pre-filled buffer proves the parser overwrites
        // rows/cols and forces the pixels stty cannot report to 0.
        $reading = "speed 9600 baud; 43 rows;\n137 columns;\n"
            . 'lflags: icanon echo echoe echok -echonl pendin';

        $ws = SizeIoctl::pack(1, 2, 3, 4);

        $parse = new \ReflectionMethod(SizeIoctl::class, 'parseSttySize');
        $parse->setAccessible(true);

        $this->assertTrue($parse->invoke(null, $reading, $ws));
        $this->assertSame(
            ['rows' => 43, 'cols' => 137, 'xpix' => 0, 'ypix' => 0],
            SizeIoctl::unpack($ws),
        );
    }

    public function testParseSttySizeRejectsGnuWordOrderAndIncompleteReadings(): void
    {
        $parse = new \ReflectionMethod(SizeIoctl::class, 'parseSttySize');
        $parse->setAccessible(true);

        $rejects = [
            // GNU coreutils shape — the Darwin lane must never be
            // satisfied by a GNU stty that word order differs from.
            'speed 38400 baud; rows 24; columns 80; line = 0;',
            // No geometry at all (proc_open failure residue, usage banner).
            'stty: Inappropriate ioctl for device',
            '',
            // One half of the geometry only.
            'speed 9600 baud; 43 rows;',
            '137 columns;',
        ];

        foreach ($rejects as $reading) {
            $ws = SizeIoctl::emptyBuffer();
            $this->assertFalse(
                $parse->invoke(null, $reading, $ws),
                'a reading that carries no BSD-shaped geometry must be rejected: ' . $reading,
            );
            $this->assertSame(
                ['rows' => 0, 'cols' => 0, 'xpix' => 0, 'ypix' => 0],
                SizeIoctl::unpack($ws),
                'a rejected reading must leave the buffer untouched',
            );
        }
    }
}
