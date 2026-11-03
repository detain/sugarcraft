<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * makeRaw() on a never-captured instance must build raw from the fd's
 * REAL current attributes, not from the zeroed constructor buffer.
 *
 * The review (m12) caught that cfmakeraw only clears bits — every flag
 * it does not touch rides through from the input bytes. A zeroed input
 * therefore applied junk (c_cflag losing CS8|CLOCAL, the whole c_cc
 * table beyond VMIN/VTIME zeroed). On a PTY that junk is mostly inert,
 * which is why the behavior tests stayed green; the class is fd-generic
 * all the same. The contract is pinned byte-exact: fresh makeRaw()
 * equals current()-then-makeRaw(), and the returned snapshot carries a
 * restore target.
 */
final class PosixTermiosMakeRawFetchTest extends TestCase
{
    private const O_RDWR = 0x0002;

    public function testMakeRawOnUncapturedInstanceMatchesCurrentThenRaw(): void
    {
        $fd = $this->requireSlaveFd();
        try {
            $rawFromFresh = (new PosixTermios($fd))->makeRaw();
            $rawFromCaptured = (new PosixTermios($fd))->current()->makeRaw();

            $this->assertSame(
                $this->dumpBuffer($rawFromCaptured),
                $this->dumpBuffer($rawFromFresh),
                'makeRaw() on an uncaptured instance must fetch current attributes '
                . 'first, not cfmakeraw the zeroed constructor buffer',
            );
        } finally {
            Libc::lib()->close($fd);
        }
    }

    public function testFreshMakeRawSnapshotIsRestorableAndRoundTripsByteExact(): void
    {
        $fd = $this->requireSlaveFd();
        $termios = new PosixTermios($fd);
        try {
            $before = $termios->current();
            $raw = $termios->makeRaw();

            $original = (new \ReflectionProperty($raw, 'original'))->getValue($raw);
            self::assertNotNull(
                $original,
                'makeRaw() on an uncaptured instance must record a restore target',
            );

            $raw->apply();
            $this->assertSame(
                $this->dumpBuffer($raw),
                $this->dumpBuffer($termios->current()),
                'apply() must land exactly the raw bytes makeRaw produced',
            );

            $raw->restore();
            $this->assertSame(
                $this->dumpBuffer($before),
                $this->dumpBuffer($termios->current()),
                'restore() on the makeRaw snapshot must return the fd byte-identical '
                . 'to the attributes fetched at makeRaw time',
            );
        } finally {
            Libc::lib()->close($fd);
        }
    }

    /**
     * Open a fresh PTY and return an fd on its slave. The master is kept
     * alive via a shutdown hook so a skipped or failing test cannot leak
     * the pair.
     */
    private function requireSlaveFd(): int
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('needs FFI and /dev/ptmx');
        }
        $pair = (new PosixPtySystem())->open(80, 24);
        \register_shutdown_function(function () use ($pair): void {
            if (!$pair->master()->isClosed()) {
                $pair->master()->close();
            }
        });
        $fd = Libc::lib()->open($pair->slave()->path(), self::O_RDWR);
        if ($fd < 0) {
            $this->markTestSkipped('could not open slave: ' . $pair->slave()->path());
        }
        return $fd;
    }

    /** The BUFSIZE bytes behind an instance's opaque buffer, as a PHP string. */
    private function dumpBuffer(PosixTermios $termios): string
    {
        $buf = (new \ReflectionProperty($termios, 'buf'))->getValue($termios);
        $size = (int) (new \ReflectionClass(PosixTermios::class))->getConstant('BUFSIZE');
        $pointer = Libc::lib()->cast('char*', \FFI::addr($buf));
        return \FFI::string($pointer, $size);
    }
}
