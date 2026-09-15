<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncConnection;
use SugarCraft\Query\Admin\EmptyServerContext;
use SugarCraft\Query\Admin\ServerContext;

/**
 * Guards the cache's two audit-driven contracts: it must be constructible for
 * injection (isolated instances, not just the process-global singleton), and
 * invalidateConnection() must force the lazily-cached async connection to be
 * rebuilt — the connection key encodes only flavor+DSN+user, which does not
 * change across a server restart, so without invalidation a reconnect would
 * keep serving admin queries on a dead socket.
 */
final class AdminQueryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testInstanceReturnsTheSharedProcessGlobal(): void
    {
        $this->assertSame(AdminQueryCache::instance(), AdminQueryCache::instance());
    }

    public function testDirectlyConstructedInstancesAreIsolated(): void
    {
        $a = new AdminQueryCache();
        $b = new AdminQueryCache();
        $a->store('SELECT 1', [['x' => '1']]);

        $this->assertSame([['x' => '1']], $a->lookup('SELECT 1'));
        $this->assertNull($b->lookup('SELECT 1'));
        $this->assertNull(AdminQueryCache::instance()->lookup('SELECT 1'));
    }

    public function testConnectionIsReusedForTheSameKey(): void
    {
        $cache = new AdminQueryCache();
        $calls = 0;
        $factory = function () use (&$calls): AsyncConnection {
            $calls++;
            return $this->createMock(AsyncConnection::class);
        };

        $first = $cache->connection('mysql|dsn|user', $factory);
        $second = $cache->connection('mysql|dsn|user', $factory);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testInvalidateConnectionForcesTheFactoryToRebuild(): void
    {
        $cache = new AdminQueryCache();
        $calls = 0;
        $factory = function () use (&$calls): AsyncConnection {
            $calls++;
            return $this->createMock(AsyncConnection::class);
        };

        $stale = $cache->connection('mysql|dsn|user', $factory);
        $cache->invalidateConnection();
        $fresh = $cache->connection('mysql|dsn|user', $factory);

        $this->assertNotSame($stale, $fresh, 'the post-invalidation connection must be a new instance');
        $this->assertSame(2, $calls);
    }

    // ---------------------------------------------------------------
    // E718: the synchronous-side ServerContext must survive admin-pane
    // resets — one memoized instance per live database handle, so its
    // status-variables TTL cache keeps covering repeated renders.
    // ---------------------------------------------------------------

    public function testServerContextMemoizesPerDatabaseHandle(): void
    {
        $cache = AdminQueryCache::instance();
        $db = new FakeDatabase();
        $calls = 0;

        $first = $cache->serverContext($db, static function () use ($db, &$calls): ServerContext {
            $calls++;
            return new ServerContext($db);
        });
        $second = $cache->serverContext($db, static function () use ($db, &$calls): ServerContext {
            $calls++;
            return new ServerContext($db);
        });

        $this->assertSame($first, $second, 'same handle must reuse the memoized context');
        $this->assertSame(1, $calls);
    }

    public function testServerContextRebuildsWhenTheDatabaseHandleChanges(): void
    {
        $cache = AdminQueryCache::instance();
        $dbA = new FakeDatabase();
        $dbB = new FakeDatabase();

        $fromA = $cache->serverContext($dbA, static fn(): ServerContext => new ServerContext($dbA));
        $fromB = $cache->serverContext($dbB, static fn(): ServerContext => new ServerContext($dbB));
        $backToA = $cache->serverContext($dbA, static fn(): ServerContext => new ServerContext($dbA));

        $this->assertNotSame($fromA, $fromB, 'a different handle must not inherit A\'s cache');
        $this->assertNotSame($fromB, $backToA, 'returning to A must rebuild after B displaced the slot');
    }

    public function testServerContextMemoIsClearedByReset(): void
    {
        $db = new FakeDatabase();
        $first = AdminQueryCache::instance()->serverContext($db, static fn(): ServerContext => new ServerContext($db));

        AdminQueryCache::reset();

        $second = AdminQueryCache::instance()->serverContext($db, static fn(): ServerContext => new ServerContext($db));
        $this->assertNotSame($first, $second, 'reset() must drop the memoized context');
    }

    public function testServerContextAcceptsAnyInterfaceImplementation(): void
    {
        $db = new FakeDatabase();
        $empty = AdminQueryCache::instance()->serverContext($db, static fn(): EmptyServerContext => new EmptyServerContext());

        $this->assertInstanceOf(EmptyServerContext::class, $empty);
    }
}
