<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionLock;

/**
 * Audit SES-3(b): the single-writer lock on one stored session.
 *
 * Two acquisitions in ONE process contend exactly as two processes do, because
 * `flock()` locks belong to the open file description, not to the process —
 * which is what lets these cases stay in-process.
 */
final class SessionLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-session-lock-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        // The lock directory first, then the sandbox it sits in.
        foreach ([$this->dir . '/' . SessionLock::DIRECTORY, $this->dir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testASecondAcquireOfAHeldSessionIsRefused(): void
    {
        $first = SessionLock::acquire($this->dir . '/sessions', 'abc123');

        self::assertNotNull($first);
        self::assertTrue($first->isEnforced());
        self::assertNull(SessionLock::acquire($this->dir . '/sessions', 'abc123'), 'the session is held');
        self::assertNotNull(SessionLock::acquire($this->dir . '/sessions', 'other'), 'other sessions are not');
    }

    public function testReleasingFreesTheSessionAndRemovesTheFile(): void
    {
        $first = SessionLock::acquire($this->dir . '/sessions', 'abc123');
        self::assertNotNull($first);
        $path = SessionLock::pathFor($this->dir . '/sessions', 'abc123');
        self::assertFileExists($path);

        $first->release();
        $first->release(); // idempotent

        self::assertFileDoesNotExist($path);
        $again = SessionLock::acquire($this->dir . '/sessions', 'abc123');
        self::assertNotNull($again);
        self::assertTrue($again->isEnforced());
    }

    public function testDroppingTheLastReferenceReleasesTheLock(): void
    {
        $lock = SessionLock::acquire($this->dir . '/sessions', 'abc123');
        self::assertNotNull($lock);
        unset($lock);

        self::assertNotNull(SessionLock::acquire($this->dir . '/sessions', 'abc123'));
    }

    public function testTheHolderPidIsRecordedForTheNotice(): void
    {
        $lock = SessionLock::acquire($this->dir . '/sessions', 'abc123');
        self::assertNotNull($lock);

        self::assertSame(getmypid(), SessionLock::holderPid($this->dir . '/sessions', 'abc123'));
        self::assertNull(SessionLock::holderPid($this->dir . '/sessions', 'nobody'));
    }

    /**
     * An id is never a path: anything outside the minted alphabet is hashed,
     * so `../x` cannot name a file outside the lock directory.
     */
    public function testAnIdOutsideTheMintedAlphabetIsHashedIntoTheDirectory(): void
    {
        $path = SessionLock::pathFor($this->dir . '/sessions', '../../escape');

        self::assertSame($this->dir . '/sessions', \dirname($path));
        self::assertStringStartsWith('h-', basename($path));
        self::assertSame($this->dir . '/sessions/3f9a.lock', SessionLock::pathFor($this->dir . '/sessions', '3f9a'));
    }

    /**
     * When locking cannot work at all the session opens WRITABLE, as every
     * session did before the lock existed — a lock directory that cannot be
     * made must not turn into a TUI that can save nothing.
     */
    public function testALockDirectoryThatCannotBeMadeFailsOpen(): void
    {
        file_put_contents($this->dir . '/blocker', 'not a directory');

        $lock = SessionLock::acquire($this->dir . '/blocker/sessions', 'abc123');

        self::assertNotNull($lock);
        self::assertFalse($lock->isEnforced());
        self::assertFalse(SessionLock::acquire(null, 'abc123')?->isEnforced() ?? true, 'no directory, no lock');
    }

    /**
     * The store keeps its locks in a `sessions/` directory beside its own
     * database — so a launch on the default store locks under
     * `~/.sugar-crush/sessions/`, and a scratch store keeps its locks in its
     * scratch tree.
     */
    public function testTheStoreLocksBesideItsDatabase(): void
    {
        $store = new EnhancedSessionStore($this->dir . '/session.db');

        $lock = $store->lockSession('abc123');

        self::assertNotNull($lock);
        self::assertTrue($lock->isEnforced());
        self::assertFileExists($this->dir . '/sessions/abc123.lock');
        self::assertNull($store->lockSession('abc123'));
        self::assertSame(getmypid(), $store->sessionLockHolder('abc123'));
    }

    public function testAnInMemoryStoreIsUnenforced(): void
    {
        $store = new EnhancedSessionStore(':memory:');

        $first = $store->lockSession('abc123');
        $second = $store->lockSession('abc123');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->isEnforced());
    }
}
