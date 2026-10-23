<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\StatusSnapshot;

/**
 * Tests for SqliteHistoryStore.
 */
final class SqliteHistoryStoreTest extends TestCase
{
    private string $dbPath;
    private SqliteHistoryStore $store;

    protected function setUp(): void
    {
        $this->dbPath = \sys_get_temp_dir() . '/candy_query_history_' . \uniqid() . '.db';
        $this->store = new SqliteHistoryStore($this->dbPath);
    }

    protected function tearDown(): void
    {
        $this->store->close();
        if (\file_exists($this->dbPath)) {
            \unlink($this->dbPath);
        }
        // Clean up WAL and SHM files
        foreach ([$this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (\file_exists($f)) {
                \unlink($f);
            }
        }
    }

    public function testSaveAndRetrieve(): void
    {
        $snapshot = new StatusSnapshot(['Queries' => '100', 'Uptime' => '3600'], 1000.0);
        $this->store->save($snapshot);

        $this->assertSame(1, $this->store->count());

        $results = $this->store->query(
            new \DateTimeImmutable('@1000'),
            new \DateTimeImmutable('@1000'),
        );

        $this->assertCount(1, $results);
        $this->assertSame(['Queries' => '100', 'Uptime' => '3600'], $results[0]->variables);
        $this->assertEqualsWithDelta(1000.0, $results[0]->ts, 0.001);
    }

    public function testQueryFiltersByTimeRange(): void
    {
        $this->store->save(new StatusSnapshot(['X' => '1'], 100.0));
        $this->store->save(new StatusSnapshot(['X' => '2'], 200.0));
        $this->store->save(new StatusSnapshot(['X' => '3'], 300.0));
        $this->store->save(new StatusSnapshot(['X' => '4'], 400.0));

        $results = $this->store->query(
            new \DateTimeImmutable('@150'),
            new \DateTimeImmutable('@350'),
        );

        $this->assertCount(2, $results);
        $this->assertSame('2', $results[0]->variables['X']);
        $this->assertSame('3', $results[1]->variables['X']);
    }

    public function testJsonRoundTripPreservesVariables(): void
    {
        $original = new StatusSnapshot([
            'Bytes_received' => '12345',
            'Bytes_sent' => '67890',
            'Version' => '8.0.33',
            'Uptime' => '86400',
        ], 999.0);

        $this->store->save($original);

        $results = $this->store->query(
            new \DateTimeImmutable('@999'),
            new \DateTimeImmutable('@999'),
        );

        $this->assertCount(1, $results);
        $this->assertSame('12345', $results[0]->variables['Bytes_received']);
        $this->assertSame('67890', $results[0]->variables['Bytes_sent']);
        $this->assertSame('8.0.33', $results[0]->variables['Version']);
        $this->assertSame('86400', $results[0]->variables['Uptime']);
    }

    public function testPruneDeletesOldSnapshots(): void
    {
        $this->store->save(new StatusSnapshot(['X' => '1'], 100.0));
        $this->store->save(new StatusSnapshot(['X' => '2'], 200.0));
        $this->store->save(new StatusSnapshot(['X' => '3'], 300.0));
        $this->store->save(new StatusSnapshot(['X' => '4'], 400.0));

        $deleted = $this->store->prune(new \DateTimeImmutable('@250'));
        $this->assertSame(2, $deleted);

        $this->assertSame(2, $this->store->count());
    }

    public function testPruneReturnsZeroWhenNothingToDelete(): void
    {
        $this->store->save(new StatusSnapshot(['X' => '1'], 100.0));

        // Cutoff at 50 is before snapshot at 100, so nothing is pruned
        $deleted = $this->store->prune(new \DateTimeImmutable('@50'));
        $this->assertSame(0, $deleted);
    }

    public function testCountAfterMultipleSaves(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->save(new StatusSnapshot(['I' => (string) $i], (float) ($i * 100)));
        }

        $this->assertSame(5, $this->store->count());
    }

    public function testEmptyQueryReturnsEmptyArray(): void
    {
        $results = $this->store->query(
            new \DateTimeImmutable('@0'),
            new \DateTimeImmutable('@100'),
        );

        $this->assertCount(0, $results);
    }

    public function testCloseDoesNotThrow(): void
    {
        $store = new SqliteHistoryStore(\sys_get_temp_dir() . '/candy_query_close_test_' . \uniqid() . '.db');
        $store->save(new StatusSnapshot(['X' => '1'], 1.0));
        $store->close();

        // Second close should not throw
        $store->close();
        $this->assertTrue(true);
    }

    public function testQueryOrderedByTimestampAscending(): void
    {
        $this->store->save(new StatusSnapshot(['X' => '3'], 300.0));
        $this->store->save(new StatusSnapshot(['X' => '1'], 100.0));
        $this->store->save(new StatusSnapshot(['X' => '2'], 200.0));

        $results = $this->store->query(
            new \DateTimeImmutable('@0'),
            new \DateTimeImmutable('@999'),
        );

        $this->assertCount(3, $results);
        $this->assertSame('1', $results[0]->variables['X']);
        $this->assertSame('2', $results[1]->variables['X']);
        $this->assertSame('3', $results[2]->variables['X']);
    }

    /**
     * Sub-second timestamp records must be preserved across the SQLite REAL
     * column round-trip. The store uses format('U.u') when building epoch strings
     * for the query bounds so that boundary records with fractional seconds are
     * not dropped.
     *
     * @see \SugarCraft\Sql\Admin\History\HistoryQuery::floatToDateTimeImmutable()
     */
    public function testSubSecondTimestampPrecisionIsPreserved(): void
    {
        $tsWithSubsecond = 100.5;

        $this->store->save(new StatusSnapshot(['Y' => 'subsecond'], $tsWithSubsecond));

        // Build a DateTimeImmutable the same way HistoryQuery does: floatToDateTimeImmutable
        $sec = (int) $tsWithSubsecond;
        $usec = (int)(($tsWithSubsecond - $sec) * 1_000_000);
        $since = \DateTimeImmutable::createFromFormat('U u', "{$sec} {$usec}") ?: new \DateTimeImmutable();
        $until = \DateTimeImmutable::createFromFormat('U u', "{$sec} {$usec}") ?: new \DateTimeImmutable();

        $results = $this->store->query($since, $until);

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta($tsWithSubsecond, $results[0]->ts, 0.001);
        $this->assertSame('subsecond', $results[0]->variables['Y']);
    }
    /**
     * A freshly created store must be private to the user even under a
     * permissive umask: directory 0700, database file 0600
     * (docs-review-1023 n4). The default location lives under $HOME.
     */
    public function testFreshlyCreatedStoreIsOwnerReadWriteOnly(): void
    {
        $dir = \sys_get_temp_dir() . '/candy_sql_perms_' . \uniqid() . '/deep';
        $path = $dir . '/history.db';

        $store = new SqliteHistoryStore($path);
        $store->close();

        $this->assertSame(0700, \fileperms($dir) & 0777, 'history directory must be 0700');
        $this->assertSame(0600, \fileperms($path) & 0777, 'history database must be 0600');

        @\unlink($path);
        @\unlink($path . '-wal');
        @\unlink($path . '-shm');
        @\rmdir($dir);
        @\rmdir(\dirname($dir));
    }
}
