<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\HistoryQuery;
use SugarCraft\Sql\Admin\History\HistoryStoreInterface;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\StatusSnapshot;

/**
 * SQL-P02 (adminpages-002 + once-034): the store used to be unbounded —
 * prune() existed with zero production callers while the default-on
 * recorder inserted ~10-20 KB JSON per poll. Retention is now wired at
 * open and at every write; rate() reads only the window boundaries.
 *
 * Can-fail proof: drop the enforceRetention() call in save() and the cap
 * tests go red (the file grows past the documented bound).
 */
final class SqliteHistoryStoreRetentionTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                $f = $p . $suffix;
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }
    }

    private function store(?int $maxRows = null, ?float $maxAge = null): SqliteHistoryStore
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-sql-hist') . '.db';
        $this->paths[] = $path;
        return new SqliteHistoryStore($path, $maxRows, $maxAge);
    }

    private function saveAt(SqliteHistoryStore $store, float $ts, int $queries): void
    {
        $store->save(new StatusSnapshot(['Queries' => (string) $queries, 'Uptime' => (string) (int) $ts], $ts));
    }

    public function testAgeCapPrunesRowsPastTheWindowOnWrite(): void
    {
        $now = microtime(true);
        $store = $this->store(maxAge: 3600.0); // 1-hour cap for the test
        $this->saveAt($store, $now - 7200, 100);   // older than the cap
        $this->saveAt($store, $now - 100, 200);     // inside the cap

        $this->assertSame(1, $store->count());
        $rows = $store->query(
            (new \DateTimeImmutable())->setTimestamp((int) $now - 10_000),
            (new \DateTimeImmutable())->setTimestamp((int) $now + 10),
        );
        $this->assertSame('200', $rows[0]->variables['Queries']);
    }

    public function testCountCapKeepsNewestRows(): void
    {
        $store = $this->store(maxRows: 10);
        $base = microtime(true) - 600;
        for ($i = 0; $i < 15; $i++) {
            $this->saveAt($store, $base + $i * 3, $i);
        }

        $this->assertSame(10, $store->count());
        $boundaries = $store->queryBoundaries(
            (new \DateTimeImmutable())->setTimestamp((int) $base - 1),
            (new \DateTimeImmutable())->setTimestamp((int) $base + 100),
        );
        // Rows 0..4 pruned; survivors are 5..14 (ascending).
        $this->assertSame((string) 5, $boundaries[0]->variables['Queries']);
        $this->assertSame((string) 14, $boundaries[1]->variables['Queries']);
    }

    public function testReopenSettlesALegacyOversizedFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-sql-hist') . '.db';
        $this->paths[] = $path;
        $base = microtime(true) - 600;
        $legacy = new SqliteHistoryStore($path, 1000, 86_400 * 365);
        for ($i = 0; $i < 12; $i++) {
            $legacy->save(new StatusSnapshot(['Queries' => (string) $i], $base + $i * 3));
        }
        $legacy->close();

        $reopened = new SqliteHistoryStore($path, 5, 86_400 * 365);
        $this->assertSame(5, $reopened->count());
    }

    public function testQueryBoundariesReadsTwoRowsNotTheWindow(): void
    {
        $store = $this->store(maxRows: 1000);
        $base = microtime(true) - 600;
        for ($i = 0; $i < 200; $i++) {
            $this->saveAt($store, $base + $i * 3, $i * 100);
        }

        $since = (new \DateTimeImmutable())->setTimestamp((int) $base - 1);
        $until = (new \DateTimeImmutable())->setTimestamp((int) ($base + 700));
        $this->assertCount(200, $store->query($since, $until));

        $boundaries = $store->queryBoundaries($since, $until);
        $this->assertCount(2, $boundaries);
        $this->assertSame('0', $boundaries[0]->variables['Queries']);
        $this->assertSame('19900', $boundaries[1]->variables['Queries']);
    }

    public function testSingleRowWindowCollapsesToOneBoundary(): void
    {
        $store = $this->store();
        $ts = microtime(true) - 30;
        $this->saveAt($store, $ts, 42);

        $boundaries = $store->queryBoundaries(
            (new \DateTimeImmutable())->setTimestamp((int) $ts - 1),
            (new \DateTimeImmutable())->setTimestamp((int) $ts + 1),
        );
        $this->assertCount(1, $boundaries);
    }

    public function testRateNeverTouchesTheFullQueryLeg(): void
    {
        // Boundary-only acceptance pin: this store answers boundaries but
        // FATS on any full-window read — the old rate() implementation
        // (which called query()) goes red here.
        $base = 1_700_400_000.0;
        $store = new class ($base) implements HistoryStoreInterface {
            public function __construct(private readonly float $base)
            {
            }

            public function save(StatusSnapshot $snapshot): void
            {
            }

            public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                throw new \LogicException('rate() must not materialize the window (SQL-P02)');
            }

            public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [
                    new StatusSnapshot(['Queries' => '1000'], $this->base),
                    new StatusSnapshot(['Queries' => '4000'], $this->base + 30.0),
                ];
            }

            public function count(): int
            {
                return 0;
            }

            public function prune(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function close(): void
            {
            }
        };

        $rate = (new HistoryQuery($store))->rate('Queries', $base, $base + 30.0);
        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(100.0, $rate, 0.001);
    }

    public function testRateOverRealStoreMatchesBoundaryMath(): void
    {
        $store = $this->store(maxRows: 1000);
        $base = microtime(true) - 600;
        for ($i = 0; $i < 60; $i++) {
            $this->saveAt($store, $base + $i * 3, $i * 30);
        }
        $hq = new HistoryQuery($store);
        // 59 rows apart in ts (0..59): delta 1770 over 177 s = 10/s.
        $rate = $hq->rate('Queries', $base - 1, $base + 180);
        $this->assertEqualsWithDelta(10.0, $rate, 0.001);
    }
}
