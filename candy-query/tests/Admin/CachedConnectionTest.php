<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\CachedConnection;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

/**
 * Regression cover for CachedConnection's DatabaseInterface compatibility.
 *
 * CachedConnection::prepare() once declared `: mixed`, which is wider than the
 * interface's `: ?PreparedStatementInterface` and made PHP fatal the moment the
 * class was loaded (so the admin section crashed on entry). Merely constructing
 * it here triggers PHP's signature validation, so this test fails to even load
 * if the incompatibility returns.
 */
final class CachedConnectionTest extends TestCase
{
    public function testIsADatabaseInterface(): void
    {
        $conn = new CachedConnection(new FakeDatabase());

        $this->assertInstanceOf(DatabaseInterface::class, $conn);
    }

    public function testPrepareDelegatesToInnerAndReturnsAPreparedStatement(): void
    {
        $conn = new CachedConnection(new FakeDatabase());

        $stmt = $conn->prepare('SELECT 1');

        $this->assertInstanceOf(PreparedStatementInterface::class, $stmt);
    }

    public function testMetadataAccessorsDelegateToInner(): void
    {
        $inner = new FakeDatabase();
        $inner->setServerVersion('MySQL version 8.0.36');
        $conn = new CachedConnection($inner);

        $this->assertSame('MySQL version 8.0.36', $conn->serverVersion());
        $this->assertSame('mysql', $conn->driverName());
    }

    public function testInjectedCacheServesQueriesWithoutTouchingTheGlobal(): void
    {
        AdminQueryCache::reset();
        try {
            $cache = new AdminQueryCache();
            $cache->store('SELECT 1', [['ok' => '1']]);
            $conn = new CachedConnection(new FakeDatabase(), $cache);

            $this->assertSame([['ok' => '1']], $conn->query('SELECT 1'));

            // A miss must queue on the injected cache, not the process-global.
            $conn->query('SELECT 2');
            $this->assertTrue($cache->hasPending());
            $this->assertFalse(AdminQueryCache::instance()->hasPending());
        } finally {
            AdminQueryCache::reset();
        }
    }

    public function testDefaultsToTheSharedCacheWhenNoneInjected(): void
    {
        AdminQueryCache::reset();
        try {
            AdminQueryCache::instance()->store('SELECT 1', [['shared' => 'y']]);

            $this->assertSame([['shared' => 'y']], (new CachedConnection(new FakeDatabase()))->query('SELECT 1'));
        } finally {
            AdminQueryCache::reset();
        }
    }
}
