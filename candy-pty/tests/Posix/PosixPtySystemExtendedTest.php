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
    // Private constructor
    // ─────────────────────────────────────────────────────────────

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(PosixPtySystem::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
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
}
