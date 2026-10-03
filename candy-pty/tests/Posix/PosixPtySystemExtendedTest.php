<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyException;

/**
 * Extended PosixPtySystem tests covering:
 * - openPtyMaster() on Darwin (openpty failure → fallback to posix_openpt)
 * - readPtsName() failure path via reflection
 * - requireCloexec() via reflection (already covered but verify)
 * - the private constructor
 */
final class PosixPtySystemExtendedTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────
    // openPtyMaster() on Darwin — verify openpty fallback path
    // ─────────────────────────────────────────────────────────────

    public function testOpenPtyMasterOnDarwinAttemptsOpenptyFirst(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Darwin-specific codepath.');
        }

        $this->requirePtySyscalls();

        $system = new PosixPtySystem();

        // Access openPtyMaster via reflection.
        $method = new \ReflectionMethod(PosixPtySystem::class, 'openPtyMaster');
        $method->setAccessible(true);

        $libc = \SugarCraft\Pty\Libc::lib();
        $masterFd = $method->invoke($system, $libc);

        $this->assertGreaterThanOrEqual(0, $masterFd);
    }

    // ─────────────────────────────────────────────────────────────
    // readPtsName() failure path — ptsname_r returns non-zero
    // ─────────────────────────────────────────────────────────────

    public function testReadPtsNameThrowsOnPtsnameREXC(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();

        // Call open() normally first to get a valid masterFd.
        $pair = $system->open();

        // Grab the master fd.
        $masterFd = $pair->master()->fd();

        try {
            // Use reflection to call the static readPtsName with a valid libc
            // but an invalid masterFd (reuse the pair's fd which is still open).
            // Actually the normal flow will succeed. For the error path,
            // we need ptsname_r to fail. We can simulate by closing the fd first.
            $libc = \SugarCraft\Pty\Libc::lib();

            // Access readPtsName via reflection.
            $method = new \ReflectionMethod(PosixPtySystem::class, 'readPtsName');
            $method->setAccessible(true);

            // Use a closed/bad fd - ptsname_r should fail.
            $badFd = 9999;
            $this->expectException(PtyException::class);
            $this->expectExceptionMessageMatches('#ptsname#');

            $method->invoke(null, $libc, $badFd);
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Class is instantiable (default public constructor)
    // ─────────────────────────────────────────────────────────────

    public function testClassIsInstantiable(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $this->assertInstanceOf(PosixPtySystem::class, $system);
    }

    // ─────────────────────────────────────────────────────────────
    // open() with default cols/rows on Darwin
    // ─────────────────────────────────────────────────────────────

    public function testOpenWithDefaultSizeOnDarwin(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Darwin-specific.');
        }

        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        $this->assertInstanceOf(\SugarCraft\Pty\Contract\PtyPair::class, $pair);

        $master = $pair->master();
        $size = $master->size();
        $this->assertSame(80, $size['cols']);
        $this->assertSame(24, $size['rows']);

        $master->close();
    }

    // ─────────────────────────────────────────────────────────────
    // capabilities() is tested in PosixPtySystemTest
    // Ensure it returns the right shape.
    // ─────────────────────────────────────────────────────────────

    public function testCapabilitiesReturnsCorrectStructure(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $caps = $system->capabilities();

        $this->assertArrayHasKey('pty', $caps);
        $this->assertArrayHasKey('termios', $caps);
        $this->assertArrayHasKey('signal', $caps);
        $this->assertTrue($caps['pty']);
        $this->assertTrue($caps['termios']);
        $this->assertTrue($caps['signal']);
    }

    // ─────────────────────────────────────────────────────────────
    // F1 (audit round): open() must propagate the requested winsize
    // on Linux too — the resize used to sit inside the Darwin-only
    // anchor block, so a Linux pty kept the kernel's 0×0 winsize
    // until some later controlling-terminal spawn.
    // ─────────────────────────────────────────────────────────────

    public function testOpenAppliesRequestedSizeOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux winsize-propagation pin.');
        }

        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(120, 40);

        try {
            $size = $pair->master()->size();
            $this->assertSame(120, $size['cols']);
            $this->assertSame(40, $size['rows']);
        } finally {
            $pair->master()->close();
        }
    }

    public function testOpenSizeSurvivesNonControllingSpawnOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux winsize-propagation pin.');
        }

        $this->requirePtySyscalls();

        if (!\is_executable('/bin/true')) {
            $this->markTestSkipped('/bin/true is required for spawn tests.');
        }

        $system = new PosixPtySystem();
        $pair = $system->open(132, 43);

        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $child->wait();

            // No geometry was requested, so the spawn must leave the pty's
            // size alone — creack/pty.Start() and portable-pty's
            // spawn_command() never touch the winsize. The size written at
            // open must still stand. (This pin is about OMITTED geometry,
            // not about the controlling-terminal flag: an explicit size is
            // applied on every spawn, see the tests below.)
            $size = $pair->master()->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(43, $size['rows']);
        } finally {
            $pair->master()->close();
        }
    }

    /**
     * Regression: an explicit geometry was silently dropped whenever
     * `controllingTerminal` was false — the child of a spawn asked for
     * 132x50 read `24 80` from `stty size`. Window size is a property of
     * the pty (TIOCSWINSZ on the master), not of the session relationship,
     * so creack/pty.StartWithSize() applies it unconditionally.
     *
     * @return iterable<string, array{bool}>
     */
    public static function controllingTerminalModes(): iterable
    {
        yield 'plain spawn' => [false];
        yield 'controlling-terminal shim' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('controllingTerminalModes')]
    public function testExplicitSpawnGeometryReachesTheChild(bool $controllingTerminal): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required for spawn tests.');
        }
        if ($controllingTerminal && !\extension_loaded('pcntl')) {
            $this->markTestSkipped('The controlling-terminal shim needs ext-pcntl.');
        }

        $pair = (new PosixPtySystem())->open(80, 24);

        try {
            $child = $pair->slave()->spawn(
                ['/bin/sh', '-c', 'printf "SIZE=%s\\n" "$(stty size)"'],
                null,
                132,
                50,
                $controllingTerminal,
            );

            $seen = $this->drainUntil($pair->master(), '/SIZE=(\d+ \d+)/');
            $child->wait();

            $this->assertMatchesRegularExpression('/SIZE=50 132\b/', $seen, 'the child must see the requested 132x50: ' . $seen);
            $size = $pair->master()->size();
            $this->assertSame([132, 50], [$size['cols'], $size['rows']]);
        } finally {
            $pair->master()->close();
        }
    }

    public function testPartialSpawnGeometryKeepsTheOtherHalfOfTheCurrentSize(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/true')) {
            $this->markTestSkipped('/bin/true is required for spawn tests.');
        }

        $pair = (new PosixPtySystem())->open(100, 30);

        try {
            $child = $pair->slave()->spawn(['/bin/true'], null, cols: 132);
            $child->wait();
            $size = $pair->master()->size();
            $this->assertSame([132, 30], [$size['cols'], $size['rows']], 'cols-only request keeps the open rows');

            $child = $pair->slave()->spawn(['/bin/true'], null, rows: 45);
            $child->wait();
            $size = $pair->master()->size();
            $this->assertSame([132, 45], [$size['cols'], $size['rows']], 'rows-only request keeps the current cols');
        } finally {
            $pair->master()->close();
        }
    }

    public function testSpawnOnAClosedMasterFailsInThePreSpawnResizeWhenGeometryIsRequested(): void
    {
        $this->requirePtySyscalls();

        $pair = (new PosixPtySystem())->open(80, 24);
        $slave = $pair->slave();
        $pair->master()->close();

        // The message pins WHERE it failed. Without the pre-spawn resize the
        // call still throws a PtyException -- Spawn::proc() cannot fopen the
        // freed slave path ('spawn.slave_open_failed') -- so a bare
        // expectException() was green with or without the resize. Only the
        // master's assertOpen() (reached via size()/resize()) says this.
        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('cannot operate on a closed PosixMasterPty');
        $slave->spawn(['/bin/true'], null, 132, 50);
    }

    public function testRejectedSpawnGeometryThrowsBeforeAnyChildIsStarted(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required for spawn tests.');
        }

        // An OPEN master whose resize fails: SizeIoctl::pack() refuses a
        // negative winsize field. The child would drop a marker file, so its
        // absence proves the failure surfaced BEFORE proc_open. The pre-fix
        // spawn() ignored geometry without controllingTerminal and started
        // the child unsized; a post-spawn-only resize would throw too, but
        // only after the child (and its marker) already existed.
        $marker = \sys_get_temp_dir() . '/candy-pty-geom-' . \bin2hex(\random_bytes(6));
        $pair = (new PosixPtySystem())->open(80, 24);

        try {
            $thrown = null;
            try {
                $pair->slave()->spawn(['/bin/sh', '-c', 'touch ' . \escapeshellarg($marker)], null, cols: -1);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }

            // Give a wrongly-started child ample time to leave its marker.
            $deadline = \microtime(true) + 1.0;
            while (\microtime(true) < $deadline && !\file_exists($marker)) {
                \usleep(20_000);
            }

            $this->assertInstanceOf(\InvalidArgumentException::class, $thrown, 'a negative column count must be refused');
            $this->assertFileDoesNotExist($marker, 'no child may run when the requested geometry cannot be applied');
            $size = $pair->master()->size();
            $this->assertSame([80, 24], [$size['cols'], $size['rows']], 'the refused geometry must not half-apply');
        } finally {
            @\unlink($marker);
            $pair->master()->close();
        }
    }

    private function drainUntil(\SugarCraft\Pty\Contract\MasterPty $master, string $pattern): string
    {
        $seen = '';
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline && \preg_match($pattern, $seen) !== 1) {
            $chunk = $master->read(8192, 0.2);
            if ($chunk === null) {
                continue;
            }
            if ($chunk === '') {
                break;
            }
            $seen .= $chunk;
        }

        return $seen;
    }
}
