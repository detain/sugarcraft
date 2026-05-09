<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\SizeIoctl;

final class ResizeTest extends TestCase
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

    public function testResizeRoundTripsThroughTiocgwinsz(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $pty->resize(120, 40);
            $size = $pty->size();

            $this->assertSame(120, $size['cols']);
            $this->assertSame(40,  $size['rows']);
            $this->assertSame(0,   $size['xpix']);
            $this->assertSame(0,   $size['ypix']);
        } finally {
            $pty->close();
        }
    }

    public function testResizeIsIdempotentWhenValuesUnchanged(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $pty->resize(100, 30);
            $pty->resize(100, 30);
            $pty->resize(100, 30);

            $size = $pty->size();
            $this->assertSame(100, $size['cols']);
            $this->assertSame(30,  $size['rows']);
        } finally {
            $pty->close();
        }
    }

    public function testResizeAcceptsZeroDimensions(): void
    {
        $this->requirePtySyscalls();

        // 0×0 is a valid winsize per the kernel — used by detached
        // sessions and by `tput` when the slave has no controlling
        // terminal yet.
        $pty = Pty::open();
        try {
            $pty->resize(0, 0);
            $size = $pty->size();
            $this->assertSame(0, $size['cols']);
            $this->assertSame(0, $size['rows']);
        } finally {
            $pty->close();
        }
    }

    public function testResizeRejectsNegativeDimensions(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $pty->resize(-1, 24);
        } finally {
            $pty->close();
        }
    }

    public function testResizeOnClosedPtyThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        $pty->resize(80, 24);
    }

    public function testSpawnDefaultsTo80x24(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = $pty->spawn(['/bin/true']);
            $child->wait();

            $size = $pty->size();
            $this->assertSame(Pty::DEFAULT_COLS, $size['cols']);
            $this->assertSame(Pty::DEFAULT_ROWS, $size['rows']);
        } finally {
            $pty->close();
        }
    }

    public function testSpawnHonoursCustomColsAndRows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = $pty->spawn(['/bin/true'], null, 132, 50);
            $child->wait();

            $size = $pty->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(50,  $size['rows']);
        } finally {
            $pty->close();
        }
    }

    public function testChildSeesTheRequestedSizeViaTput(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/usr/bin/tput') && !\is_executable('/bin/tput')) {
            $this->markTestSkipped('tput is required to verify child-side TIOCGWINSZ.');
        }

        $pty = Pty::open();
        $tmp = \tempnam(\sys_get_temp_dir(), 'candy-pty-tput-');
        $this->assertNotFalse($tmp);

        try {
            $pty->resize(95, 33);

            // Redirect tput's stdout to a regular file so we observe
            // the child's view of the slave-side winsize without
            // needing the master-fd read primitive that PR4 ships.
            // tput queries TIOCGWINSZ on stdin (the slave PTY), not
            // stdout — redirection of stdout doesn't affect the query.
            $child = $pty->spawn(
                ['/bin/sh', '-c', "tput cols > {$tmp}; tput lines >> {$tmp}"],
                ['TERM' => 'xterm-256color', 'PATH' => '/usr/bin:/bin'],
                95,
                33,
            );
            $exit = $child->wait();
            $this->assertSame(0, $exit, 'sh -c tput pipeline must exit zero');

            $out = (string) \file_get_contents($tmp);
            $lines = \array_values(\array_filter(\explode("\n", \trim($out)), 'is_numeric'));
            $this->assertCount(2, $lines, "expected two numeric lines from tput, got: {$out}");
            $this->assertSame('95', $lines[0]);
            $this->assertSame('33', $lines[1]);
        } finally {
            if (\file_exists($tmp)) {
                \unlink($tmp);
            }
            $pty->close();
        }
    }

    public function testSizeIoctlConstantsMatchDocumentedValues(): void
    {
        // Guard against accidental constant edits — these numbers come
        // from /usr/include/asm-generic/ioctls.h (Linux) and
        // /usr/include/sys/ttycom.h (macOS) and don't change.
        $this->assertSame(0x5414,     SizeIoctl::LINUX_TIOCSWINSZ);
        $this->assertSame(0x5413,     SizeIoctl::LINUX_TIOCGWINSZ);
        $this->assertSame(0x80087467, SizeIoctl::DARWIN_TIOCSWINSZ);
        $this->assertSame(0x40087468, SizeIoctl::DARWIN_TIOCGWINSZ);
    }

    public function testSetRequestPicksHostPlatformConstant(): void
    {
        $expected = PHP_OS_FAMILY === 'Darwin'
            ? SizeIoctl::DARWIN_TIOCSWINSZ
            : SizeIoctl::LINUX_TIOCSWINSZ;
        $this->assertSame($expected, SizeIoctl::setRequest());
    }

    public function testGetRequestPicksHostPlatformConstant(): void
    {
        $expected = PHP_OS_FAMILY === 'Darwin'
            ? SizeIoctl::DARWIN_TIOCGWINSZ
            : SizeIoctl::LINUX_TIOCGWINSZ;
        $this->assertSame($expected, SizeIoctl::getRequest());
    }
}
