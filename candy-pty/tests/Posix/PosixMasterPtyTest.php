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

    /**
     * `close()` on a master that was READ FROM OR WRITTEN TO releases every
     * descriptor it opened -- including the one `close()` itself makes.
     *
     * ## Why this is not "obviously fine" and why it needs the control beside
     * it
     *
     * `close()` takes a different path once {@see PosixMasterPty::stream()}
     * has materialised `php://fd/<n>`, and that path duplicates the master
     * descriptor before closing it, deliberately, to hold the description
     * alive across the close. The duplicate has to be released again; one
     * that is not is a leak of exactly one descriptor per pty, and every
     * leaked descriptor still pins the master side of a terminal the caller
     * believes it has closed. That is invisible to every other test in this
     * package, because a leaked descriptor changes no return value.
     *
     * MEASURED before the fix, PHP 8.3.6, this box: five open/write/close
     * cycles leaked 1, 2, 3, 4, 5 `/dev/ptmx` descriptors -- linear in the
     * number of closes. It was found from candy-core, where an un-skipped
     * test drove one such cycle and the NEXT test's `/proc/self/fd` walk
     * found two descriptors on one device where it requires exactly one.
     *
     * The second loop is the control, and it is what makes the first one
     * evidence: it runs the same number of cycles WITHOUT ever touching the
     * stream, so it takes the other branch of `close()`. If the census
     * itself were broken -- reading the wrong link target, or scanning
     * nothing -- both loops would report zero and this test would pass in a
     * tree where the leak was back. They must disagree in the way described,
     * not merely both be small.
     */
    public function testClosingAMasterThatWasWrittenToLeaksNoDescriptor(): void
    {
        $this->requirePtySyscalls();

        $before = self::openPtmxDescriptors();

        for ($i = 0; $i < 5; $i++) {
            $pair = (new PosixPtySystem())->open();
            // Materialises php://fd/<n>, which is what selects the branch
            // of close() under test.
            $pair->master()->write('x');
            $pair->master()->close();
        }

        $afterStreamPath = self::openPtmxDescriptors();

        for ($i = 0; $i < 5; $i++) {
            $pair = (new PosixPtySystem())->open();
            $pair->master()->close();
        }

        $afterPureLibcPath = self::openPtmxDescriptors();

        $this->assertSame(
            [],
            array_values(array_diff($afterStreamPath, $before)),
            'close() leaked a /dev/ptmx descriptor after the master was written to. The dup() in '
            . 'PosixMasterPty::close() must be closed again, not discarded.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($afterPureLibcPath, $before)),
            'close() leaked a /dev/ptmx descriptor on the pure-libc path',
        );

        // The control: the census can see a descriptor when there is one.
        // Without this, a scanner that returns [] unconditionally passes
        // both assertions above.
        $held = (new PosixPtySystem())->open();
        $held->master()->write('x');
        $witnessed = array_values(array_diff(self::openPtmxDescriptors(), $before));
        $held->master()->close();

        $this->assertNotSame(
            [],
            $witnessed,
            'the descriptor census reported nothing while a master pty was open, so the two '
            . 'assertions above were asserting an absence nothing could have contradicted',
        );
        $this->assertSame(
            [],
            array_values(array_diff(self::openPtmxDescriptors(), $before)),
            'the witness pty was not released',
        );
    }

    /**
     * Every descriptor of this process whose `/proc/self/fd` link points at
     * `/dev/ptmx`, as a sorted list of numbers.
     *
     * Linux-specific and deliberately so: the test above is gated on
     * `/dev/ptmx` being usable at all, and there is no portable way to
     * enumerate a process's descriptors. On a host without procfs the walk
     * finds nothing, both loops answer `[]`, and the witness assertion at the
     * end fails rather than letting the test pass on a census that cannot
     * see.
     *
     * @return list<int>
     */
    private static function openPtmxDescriptors(): array
    {
        $found = [];
        foreach ((array) @scandir('/proc/self/fd') as $entry) {
            if (!\is_string($entry) || !\ctype_digit($entry)) {
                continue;
            }
            if (@readlink('/proc/self/fd/' . $entry) === '/dev/ptmx') {
                $found[] = (int) $entry;
            }
        }
        sort($found);

        return $found;
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
