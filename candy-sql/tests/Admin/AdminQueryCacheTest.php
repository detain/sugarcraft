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
        $cache->storeStatusSnapshot(['Uptime' => '9']);

        $cache->connection('mysql|dsn|user', fn () => $this->spyConnection());

        $this->assertNotNull($cache->statusVariables(), 'the first dial must keep pre-adopted snapshots');
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

        $this->assertNotSame($fromA, $fromB, 'a different handle must not inherit A\'s cache');
    }

    public function testServerContextSurvivesHandleAlternation(): void
    {
        // admindata-018: the old single-slot memo displaced A when B entered,
        // so every alternation re-minted a cold context (cold TTL cache,
        // restart-latch reset, re-armed fetch era). With the bounded handle
        // map, A/B/A reuses A's context — one build per live handle.
        $cache = AdminQueryCache::instance();
        $dbA = new FakeDatabase();
        $dbB = new FakeDatabase();
        $buildsA = 0;
        $buildsB = 0;

        $firstA = $cache->serverContext($dbA, static function () use ($dbA, &$buildsA): ServerContext {
            $buildsA++;
            return new ServerContext($dbA);
        });
        $fromB = $cache->serverContext($dbB, static function () use ($dbB, &$buildsB): ServerContext {
            $buildsB++;
            return new ServerContext($dbB);
        });
        $backToA = $cache->serverContext($dbA, static function () use ($dbA, &$buildsA): ServerContext {
            $buildsA++;
            return new ServerContext($dbA);
        });

        $this->assertSame($firstA, $backToA, 'returning to a still-live handle must reuse its context');
        $this->assertSame(1, $buildsA, 'handle A must be built exactly once across alternation');
        $this->assertSame(1, $buildsB);
        $this->assertNotSame($firstA, $fromB, 'A and B still never share a context');
    }

    public function testServerContextMapEvictsBeyondItsCap(): void
    {
        // The map is bounded (MAX_SERVER_CONTEXTS=3) and LRU: a fourth live
        // handle retires the LEAST recently used slot, so dead-but-pinned
        // contexts cannot pile up while hot handles stay warm.
        $cache = AdminQueryCache::instance();
        $handles = [new FakeDatabase(), new FakeDatabase(), new FakeDatabase(), new FakeDatabase()];
        $builds = 0;
        $get = static function (FakeDatabase $db) use ($cache, &$builds): ServerContext {
            return $cache->serverContext($db, static function () use ($db, &$builds): ServerContext {
                $builds++;
                return new ServerContext($db);
            });
        };

        $first0 = $get($handles[0]);
        $first1 = $get($handles[1]);
        $get($handles[2]);
        $this->assertSame(3, $builds);

        $this->assertSame($first0, $get($handles[0]), 'a warm handle reuses its context');
        $this->assertSame(3, $builds, 'a memo hit must not build');

        $get($handles[3]); // cap reached: evicts handles[1] (least recently used)
        $this->assertSame(4, $builds);

        $this->assertSame($first0, $get($handles[0]), 'the most recently touched slot survives the eviction');
        $this->assertSame(4, $builds);

        $this->assertNotSame($first1, $get($handles[1]), 'the evicted handle rebuilds on return');
        $this->assertSame(5, $builds);
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

    // ---------------------------------------------------------------
    // SQL-A06: typed snapshot slots, retention sweep, LRU context map
    // ---------------------------------------------------------------

    public function testStoreRejectsFlattenedMapsIntoTheRowListSpace(): void
    {
        // admindata-011: the shape split is enforced at the boundary, not by
        // docblock wishful thinking — a map smuggled under a SQL-ish key is
        // exactly how the 'status' pseudo-key contamination started.
        $cache = new AdminQueryCache();

        $this->expectException(\InvalidArgumentException::class);
        $cache->store('status', ['Uptime' => '7']);
    }

    public function testEmptyRowsStillStoreThroughTheRowListDoor(): void
    {
        // The guard fires on non-empty non-lists only: an empty result is a
        // legitimate drain success (and markError owns the failure story).
        $cache = new AdminQueryCache();
        $cache->store('SELECT none', []);

        $this->assertSame([], $cache->lookup('SELECT none'));
    }

    public function testSnapshotSlotsRoundTripAndAgeOut(): void
    {
        $cache = new AdminQueryCache();
        $this->assertNull($cache->statusVariables());
        $this->assertNull($cache->statusSnapshotAt());
        $this->assertNull($cache->serverSnapshotAt());

        $cache->storeStatusSnapshot(['Uptime' => '9']);
        $cache->storeServerSnapshot(['max_connections' => '151']);

        $this->assertSame(['Uptime' => '9'], $cache->statusVariables());
        $this->assertSame(['max_connections' => '151'], $cache->serverVariables());
        $this->assertNotNull($cache->statusSnapshotAt());
        $this->assertNotNull($cache->serverSnapshotAt());

        // Age both stamps past TTL (3 s): stale slots serve null (the render
        // path shows its loading shape) and the refresh cadence is owned by
        // the full admin tick — the pending set must stay pure SQL text.
        $stamp = new \ReflectionProperty(AdminQueryCache::class, 'statusSnapshotAt');
        $stamp->setValue($cache, $stamp->getValue($cache) - 10.0);
        $srvStamp = new \ReflectionProperty(AdminQueryCache::class, 'serverSnapshotAt');
        $srvStamp->setValue($cache, $srvStamp->getValue($cache) - 10.0);

        $this->assertNull($cache->statusVariables());
        $this->assertNull($cache->serverVariables());
        $this->assertSame(
            [],
            $cache->takePending(),
            'snapshot staleness must not leak pseudo-keys into the pending SQL set (admindata-011)',
        );
    }

    public function testResidencySweepRetiresStaleRowLists(): void
    {
        // admindata-017: TTL is 3 s, retention horizon is 8 windows = 24 s.
        $cache = new AdminQueryCache();
        $cache->store('SELECT fresh', [['x' => '1']]);
        $cache->store('SELECT ancient_processlist', array_fill(0, 3, ['Id' => '1']));

        $storedAt = new \ReflectionProperty(AdminQueryCache::class, 'storedAt');
        $now = microtime(true);
        $storedAt->setValue($cache, [
            'SELECT fresh' => $now - 10.0,
            'SELECT ancient_processlist' => $now - 25.0,
        ]);

        $cache->store('SELECT anything', [['x' => '1']]); // any store sweeps

        $this->assertNotNull($cache->lookup('SELECT fresh'), 'inside the horizon the last-known rows still serve');
        $this->assertNull(
            (static function (AdminQueryCache $c): ?array {
                // direct peek: lookup() re-pends a miss; test the slot itself
                $results = new \ReflectionProperty(AdminQueryCache::class, 'results');
                return $results->getValue($c)['SELECT ancient_processlist'] ?? null;
            })($cache),
            'payload past the retention horizon must not squat in the cache',
        );
    }

    public function testInvalidateRetiresAdoptedSnapshots(): void
    {
        $cache = new AdminQueryCache();
        $cache->storeStatusSnapshot(['Uptime' => '9']);
        $cache->storeServerSnapshot(['max_connections' => '151']);
        $cache->connection('mysql|dsn|user', fn () => $this->spyConnection());
        $this->assertNotNull($cache->statusVariables(), 'the first dial must keep pre-adopted snapshots');

        $cache->invalidateConnection();

        $this->assertNull($cache->statusVariables(), 'invalidation retires session-derived snapshots too (admindata-007)');
        $this->assertNull($cache->serverVariables());
        $this->assertNull($cache->statusSnapshotAt());
    }

    public function testForgetClearsSnapshotSlots(): void
    {
        $cache = new AdminQueryCache();
        $cache->storeStatusSnapshot(['Uptime' => '9']);

        $cache->forget();

        $this->assertNull($cache->statusVariables(), 'an explicit refresh forgets the snapshots as well');
    }
}
