<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;

final class PosixMasterPtyTest extends TestCase
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

    public function testReadWriteRoundTrip(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/echo', 'hello']);
            $child->wait();

            $captured = '';
            $deadline = \microtime(true) + 1.0;
            while (\microtime(true) < $deadline) {
                $chunk = $master->read(4096);
                if ($chunk === null) {
                    break;
                }
                if ($chunk === '') {
                    \usleep(10_000);
                    continue;
                }
                $captured .= $chunk;
            }

            $this->assertStringContainsString('hello', $captured);
        } finally {
            $pair->master()->close();
        }
    }

    public function testResizeUpdatesSize(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $master = $pair->master();
            $master->resize(132, 40);
            $size = $master->size();

            $this->assertSame(132, $size['cols']);
            $this->assertSame(40, $size['rows']);
        } finally {
            $pair->master()->close();
        }
    }

    public function testStreamReturnsCachedResource(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $master = $pair->master();
            $a = $master->stream();
            $b = $master->stream();
            $this->assertSame($a, $b, 'stream() must cache the resource');
            $this->assertIsResource($a);
        } finally {
            $pair->master()->close();
        }
    }

    public function testWriteReturnsBytesWritten(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $written = $pair->master()->write("test\n");
            $this->assertSame(5, $written);
        } finally {
            $pair->master()->close();
        }
    }

    public function testReadWithTimeoutReturnsNullWhenIdle(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $master = $pair->master();
            $start = \microtime(true);
            $bytes = $master->read(1024, 0.05);
            $elapsed = \microtime(true) - $start;

            $this->assertNull($bytes, 'read() must return null on timeout');
            $this->assertGreaterThanOrEqual(0.04, $elapsed);
        } finally {
            $pair->master()->close();
        }
    }

    public function testReadOnClosedMasterThrows(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $master->close();

        $this->expectException(PtyException::class);
        $master->read();
    }

    public function testWriteOnClosedMasterThrows(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $master->close();

        $this->expectException(PtyException::class);
        $master->write('x');
    }

    public function testResizeOnClosedMasterThrows(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $master->close();

        $this->expectException(PtyException::class);
        $master->resize(80, 24);
    }

    public function testSizeOnClosedMasterThrows(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $master->close();

        $this->expectException(PtyException::class);
        $master->size();
    }

    public function testCloseIsIdempotent(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();

        $master->close();
        $master->close();

        $this->assertTrue(true, 'idempotent close must not throw');
    }
}
