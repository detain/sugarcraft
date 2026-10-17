<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionLock;

/**
 * ISS-079's probe: EnhancedSessionStore::liveLockHolder() asks the flock
 * whether a session is open NOW, where sessionLockHolder() merely reads the
 * pid whoever wrote last. The pair's asymmetry is the point — the file lies
 * after a crash, the flock cannot — and the verbs gate on the former
 * ({@see \SugarCraft\Code\Tests\Cli\SubcommandsSessionLockGateTest}).
 */
final class SessionLiveLockHolderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iss079-probe-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/' . chr(42)) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/' . SessionLock::DIRECTORY);
        @rmdir($this->dir);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dir . '/session.db');
    }

    public function testAFreeSessionHasNoLiveHolder(): void
    {
        $this->assertNull($this->store()->liveLockHolder('sess-0001'));
    }

    public function testTheProbeLeavesNoLockBehind(): void
    {
        $this->store()->liveLockHolder('sess-0001');

        $this->assertFileDoesNotExist(
            SessionLock::pathFor($this->dir . '/' . SessionLock::DIRECTORY, 'sess-0001'),
            'the probe took the lock and forgot to give it back — every verb behind it would now refuse.',
        );
    }

    public function testAHeldLockIsReportedWithItsHolderPid(): void
    {
        // A held handle kept alive in a variable: the second store instance
        // over the same path is exactly what another process would see.
        $held = $this->store()->lockSession('sess-0001');
        $this->assertNotNull($held);

        $this->assertSame(getmypid(), $this->store()->liveLockHolder('sess-0001'));
    }

    public function testAStalePidFileHoldsNobody(): void
    {
        $directory = $this->dir . '/' . SessionLock::DIRECTORY;
        mkdir($directory, 0700, true);
        file_put_contents(SessionLock::pathFor($directory, 'sess-0001'), '999999' . "\n");

        $store = $this->store();
        $this->assertSame(999999, $store->sessionLockHolder('sess-0001'), 'the file reader sees the dead window.');
        $this->assertNull($store->liveLockHolder('sess-0001'), 'the flock probe does not.');
    }

    public function testAnInMemoryStoreIsUnenforcedAndNeverRefuses(): void
    {
        $this->assertNull((new EnhancedSessionStore(':memory:'))->liveLockHolder('sess-0001'));
    }
}
