<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Support\SqliteConnect;
use SugarCraft\Code\Usage;

/**
 * SES-1: the armed busy timeout is a property of the live connection, not
 * of the source text — each store is asked directly what `PRAGMA
 * busy_timeout` answers — and the silent half of the finding is fixed:
 * `appendUsageRecord()` still fail-softs, but a real refusal now says so
 * once on the runtime-notice seam instead of dropping a durable spend row
 * without a sound.
 *
 * The cross-process contention itself (two connections, wait-not-drop)
 * rides {@see SessionStoreBusyContentionTest}; this file pins the two
 * halves that test cannot attribute on its own.
 */
final class SessionStoreBusyTimeoutTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/busyarm_' . \uniqid('', true);
        \mkdir($this->dir, 0700, true);
        EnhancedSessionStore::forgetDroppedUsageNotice();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testEveryStoreConnectionCarriesTheArmedWait(): void
    {
        $stores = [
            'SessionStore' => new SessionStore($this->dir . '/sessions.db'),
            'ShareStore' => ShareStore::atPath($this->dir . '/shares.db'),
            'EnhancedSessionStore' => new EnhancedSessionStore($this->dir . '/enhanced.db'),
        ];

        foreach ($stores as $name => $store) {
            $pdo = (new ReflectionProperty($store, 'pdo'))->getValue($store);
            self::assertInstanceOf(\PDO::class, $pdo);
            self::assertSame(
                SqliteConnect::BUSY_TIMEOUT_MS,
                (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
                "{$name}'s live connection does not carry the wait SES-1 armed — the immediateTransaction docblock lies without it",
            );
        }
    }

    public function testADroppedSpendRowAnnouncesOnceInsteadOfVanishingSilently(): void
    {
        $store = new EnhancedSessionStore($this->dir . '/loud.db');

        // Force the refusal the finding is about without racing for it:
        // the ledger table disappears under a store that still believes in
        // it, which fails the write the same way SQLITE_BUSY does — through
        // the same catch that used to swallow it in silence.
        (new ReflectionProperty($store, 'pdo'))
            ->getValue($store)
            ->exec('DROP TABLE usage_records');

        self::assertFalse(
            $store->appendUsageRecord('loud-session', Usage::new(100, 0.01), 'test-model'),
            'the fail-soft return is unchanged: a busy ledger must never cost the user their turn',
        );
        self::assertFalse(
            $store->appendUsageRecord('loud-session', Usage::new(100, 0.01), 'test-model'),
        );

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices, 'the drop is loud once, not silent — and not one transcript row per call either');
        self::assertStringContainsString('could not record a provider call', $notices[0]);
        self::assertStringContainsString('/usage reads low', $notices[0]);
    }

    public function testTheLatchIsPerProcessAndResettable(): void
    {
        $store = new EnhancedSessionStore($this->dir . '/latch.db');
        (new ReflectionProperty($store, 'pdo'))
            ->getValue($store)
            ->exec('DROP TABLE usage_records');

        $store->appendUsageRecord('latch-first', Usage::new(1, 0.0), 'test-model');
        EnhancedSessionStore::forgetDroppedUsageNotice();
        $store->appendUsageRecord('latch-second', Usage::new(1, 0.0), 'test-model');

        self::assertCount(2, RuntimeNoticeSink::drain(), 'the forget seam re-arms the notice, mirroring forgetAnnouncedSnapshots()');
    }
}
