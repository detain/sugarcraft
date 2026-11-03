<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * Read-taxonomy pins for PosixMasterPty: '' is EOF and ONLY EOF, null is
 * "nothing this instant", and the bare one-argument read() is bounded by a
 * finite default timeout so it can never hang a caller. fd() joins the
 * closed-handle refusal family.
 */
final class PosixMasterPtyReadTaxonomyTest extends TestCase
{
    public function testBareReadOnBlockingIdleMasterIsBoundedNotHanging(): void
    {
        $this->requirePty();

        $master = (new PosixPtySystem())->open()->master();
        try {
            $start = \microtime(true);
            $bytes = $master->read(1024);
            $elapsed = \microtime(true) - $start;

            self::assertNull($bytes, 'an idle read must report null');
            self::assertGreaterThanOrEqual(0.04, $elapsed, 'the finite default (0.05s) must actually bound the wait');
            self::assertLessThan(1.0, $elapsed, 'the bare shape must return promptly, never hang');
        } finally {
            $master->close();
        }
    }

    public function testExplicitNullTimeoutOnNonBlockingStreamReportsNullImmediately(): void
    {
        $this->requirePty();

        $master = (new PosixPtySystem())->open()->master();
        try {
            \stream_set_blocking($master->stream(), false);
            $start = \microtime(true);
            $bytes = $master->read(1024, null);
            $elapsed = \microtime(true) - $start;

            self::assertNull($bytes, 'null timeout on a non-blocking stream is the mode-governed immediate shape');
            self::assertLessThan(0.05, $elapsed, 'the explicit-null contract skips the select arm entirely');
        } finally {
            $master->close();
        }
    }

    public function testIdleNonBlockingReadIsNullNotEmptyString(): void
    {
        $this->requirePty();

        $master = (new PosixPtySystem())->open()->master();
        try {
            \stream_set_blocking($master->stream(), false);
            $bytes = $master->read(1024, 0.0);
            self::assertNull($bytes,
                'a zero-length would-block read must never masquerade as EOF');
        } finally {
            $master->close();
        }
    }

    public function testConfirmedEofStillReportsEmptyStringAfterData(): void
    {
        $this->requirePty();

        $pair = (new PosixPtySystem())->open();
        $master = $pair->master();
        $marker = 'taxonomy-' . \uniqid('', true);
        try {
            $child = $pair->slave()->spawn(['/bin/echo', $marker]);
            $child->wait();

            $captured = '';
            $sawEof = false;
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline) {
                $chunk = $master->read(4096, 0.05);
                if ($chunk === '') {
                    $sawEof = true;
                    break;
                }
                if ($chunk !== null) {
                    $captured .= $chunk;
                }
            }

            self::assertStringContainsString($marker, $captured);
            self::assertTrue($sawEof, "'' must arrive once every slave is closed (EOF)");
        } finally {
            $master->close();
        }
    }

    public function testFdAfterCloseRefusesLikeTheRestOfTheHandleFamily(): void
    {
        $this->requirePty();

        $master = (new PosixPtySystem())->open()->master();
        self::assertGreaterThanOrEqual(0, $master->fd());
        $master->close();

        $this->expectException(PtyException::class);
        $master->fd();
    }

    private function requirePty(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('a usable PTY (readable/writable /dev/ptmx via working FFI) is required.');
        }
    }
}
