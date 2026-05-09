<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;

final class IoTest extends TestCase
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

    public function testStreamReturnsCachedResource(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $a = $pty->stream();
            $b = $pty->stream();
            $this->assertSame($a, $b, 'stream() must cache the resource');
            $this->assertIsResource($a);
        } finally {
            $pty->close();
        }
    }

    public function testNonBlockingReadOnEmptyPtyReturnsEmptyString(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $pty->setBlocking(false);
            $start = \microtime(true);
            $bytes = $pty->read(1024);
            $elapsed = \microtime(true) - $start;

            $this->assertSame('', $bytes, 'non-blocking read on empty PTY must return empty string');
            $this->assertLessThan(0.05, $elapsed, 'non-blocking read must return immediately');
        } finally {
            $pty->close();
        }
    }

    public function testReadWithTimeoutReturnsNullWhenIdleElapses(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $start = \microtime(true);
            $bytes = $pty->read(1024, 0.05);
            $elapsed = \microtime(true) - $start;

            $this->assertNull($bytes, 'read() must return null on timeout');
            $this->assertGreaterThanOrEqual(0.04, $elapsed, 'timeout must actually wait at least the requested duration (with slack)');
            $this->assertLessThan(0.5, $elapsed, 'timeout must not over-shoot wildly');
        } finally {
            $pty->close();
        }
    }

    public function testWriteReturnsBytesWritten(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $written = $pty->write("hello\n");
            $this->assertSame(6, $written);
        } finally {
            $pty->close();
        }
    }

    public function testReadCapturesEchoOutput(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/echo') && !\is_executable('/usr/bin/echo')) {
            $this->markTestSkipped('echo binary not found.');
        }

        $pty = Pty::open();
        try {
            $marker = 'candy-pty-marker-' . \bin2hex(\random_bytes(4));
            $child = $pty->spawn(['/bin/sh', '-c', "echo {$marker}"]);
            $child->wait();

            // Drain master in non-blocking mode until either we see the
            // marker or the deadline elapses. PTY cooked-mode wraps the
            // \n into \r\n on the way back to the master, so we look
            // for the marker substring rather than asserting on a full
            // byte sequence.
            $pty->setBlocking(false);
            $captured = '';
            $deadline = \microtime(true) + 1.0;
            while (\microtime(true) < $deadline) {
                $chunk = $pty->read(4096);
                if ($chunk === null) {
                    break;
                }
                if ($chunk === '') {
                    if (\str_contains($captured, $marker)) {
                        break;
                    }
                    \usleep(10_000);
                    continue;
                }
                $captured .= $chunk;
            }

            $this->assertStringContainsString($marker, $captured, "child output never reached master within 1s; captured: " . \var_export($captured, true));
        } finally {
            $pty->close();
        }
    }

    public function testSetBlockingFalseMakesReadReturnImmediately(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            // Default mode is blocking; switch to non-blocking and
            // verify read() returns instantly when there's no data.
            $pty->setBlocking(false);
            $start = \microtime(true);
            $bytes = $pty->read(1024);
            $elapsed = \microtime(true) - $start;

            $this->assertSame('', $bytes);
            $this->assertLessThan(0.02, $elapsed, 'non-blocking read must not wait');
        } finally {
            $pty->close();
        }
    }

    public function testReadInvalidLengthRejected(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $pty->read(0);
        } finally {
            $pty->close();
        }
    }

    public function testReadNegativeTimeoutRejected(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $pty->read(1024, -0.5);
        } finally {
            $pty->close();
        }
    }

    public function testReadOnClosedPtyThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        $pty->read();
    }

    public function testWriteOnClosedPtyThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        $pty->write('x');
    }

    public function testSetBlockingOnClosedPtyThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        $pty->setBlocking(false);
    }

    public function testCloseRoutesThroughFcloseWhenStreamMaterialised(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $stream = $pty->stream();
        $this->assertIsResource($stream);

        $pty->close();
        $this->assertTrue($pty->isClosed());

        // After close(), the stream resource the wrapper held must
        // also be closed (fclose() owns the underlying fd).
        $this->assertFalse(\is_resource($stream), 'close() must fclose() the materialised stream resource');

        // Idempotent.
        $pty->close();
        $this->assertTrue($pty->isClosed());
    }

    public function testCloseUsesLibcWhenStreamNeverMaterialised(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        // Don't call stream() / read() / write() / setBlocking() — the
        // Libc::close() path runs and the test (PR1's contract) still
        // produces a clean teardown.
        $pty->close();
        $this->assertTrue($pty->isClosed());
    }
}
