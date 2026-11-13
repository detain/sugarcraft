<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\Admin\EmptyServerContext;
use SugarCraft\Sql\Admin\ServerContext;

/**
 * Guards the cache's two audit-driven contracts: it must be constructible for
 * injection (isolated instances, not just the process-global singleton), and
 * dropping a connection must both CLOSE the wrapper and retire the rows its
 * session produced (admindata-004/-007). The key encodes flavor + DSN + user +
 * credential fingerprint (admindata-010), which still does not change across a
 * server restart — so without explicit invalidation a reconnect would keep
 * serving admin queries on a dead socket.
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
    // admindata-004 + -007: dropping a wrapper is not retiring it. The
    // held AsyncConnection must be CLOSE()d on every drop path (key swap
    // and invalidation), and the rows its session produced must not
    // survive as if they belonged to the new one.
    // ---------------------------------------------------------------

    private function spyConnection(): AsyncConnection
    {
        return new class implements AsyncConnection {
            public bool $closed = false;

            public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve([]);
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }

    public function testInvalidateConnectionClosesTheHeldConnection(): void
    {
        $spy = $this->spyConnection();
        $cache = new AdminQueryCache();
        $cache->connection('mysql|dsn|user', static fn () => $spy);

        $cache->invalidateConnection();

        $this->assertTrue($spy->closed, 'the retired wrapper must not linger as a live server session');
    }

    public function testKeySwapClosesThePreviousConnection(): void
    {
        $old = $this->spyConnection();
        $new = $this->spyConnection();
        $cache = new AdminQueryCache();
        $cache->connection('mysql|dsn|user', static fn () => $old);

        $returned = $cache->connection('pgsql|other|admin', static fn () => $new);

        $this->assertSame($new, $returned);
        $this->assertTrue($old->closed, 'the displaced session is closed, not dropped');
        $this->assertFalse($new->closed, 'the surviving session stays open');
    }

    public function testConnectionDropClearsSessionRows(): void
    {
        $cache = new AdminQueryCache();
        $cache->store('SELECT 1', [['x' => 'keep']]);
        $cache->connection('mysql|dsn|user', fn () => $this->spyConnection());

        $cache->invalidateConnection();

        $this->assertSame([], $cache->takePending(), 'old pending fetches belong to the dead session too');
        $this->assertNull($cache->lookup('SELECT 1'), 'rows from the retired session must not be served behind the new dial');
    }

    public function testKeySwapClearsRowsFromTheOtherServer(): void
    {
        $cache = new AdminQueryCache();
        $cache->connection('mysql|dsnA|user', fn () => $this->spyConnection());
        $cache->store('SELECT 1', [['x' => 'from-A']]);

        $cache->connection('mysql|dsnB|user', fn () => $this->spyConnection());

        $this->assertNull($cache->lookup('SELECT 1'), 'server B must not inherit server A\'s rows under the same SQL text');
    }

    public function testFirstDialNeverPurgesAdoptedRows(): void
    {
        // A03's adoption path legitimately stores snapshots BEFORE any async
        // leg has dialed; the first-ever connection() must not treat that
        // warm data as belonging to a dead session.
        $cache = new AdminQueryCache();
        $cache->store('status', [['Variable_name' => 'Uptime', 'Value' => '9']]);

        $cache->connection('mysql|dsn|user', fn () => $this->spyConnection());

        $this->assertNotNull($cache->lookup('status'), 'the first dial must keep pre-adopted snapshots');
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
