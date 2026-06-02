<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Tests\Admin\FakePostgresDatabase;

/**
 * Tests for PostgresAdminProvider stub functionality.
 */
final class PostgresAdminProviderTest extends TestCase
{
    private FakePostgresDatabase $db;
    private PostgresAdminProvider $provider;

    protected function setUp(): void
    {
        $this->db = new FakePostgresDatabase();
        $this->provider = PostgresAdminProvider::new($this->db);
    }

    public function testImplementsAdminProviderInterface(): void
    {
        $this->assertInstanceOf(AdminProviderInterface::class, $this->provider);
    }

    public function testFlavorReturnsPostgres(): void
    {
        $this->assertSame(Flavor::Postgres, $this->provider->flavor());
    }

    public function testFetchStatusVariablesReturnsPgStatDatabaseMetrics(): void
    {
        $this->db->setQueryResult('pg_stat_database', [
            [
                'numbackends' => '3',
                'xact_commit' => '1000',
                'xact_rollback' => '5',
                'blks_read' => '200',
                'blks_hit' => '5000',
                'tup_returned' => '10000',
                'tup_fetched' => '500',
                'tup_inserted' => '100',
                'tup_updated' => '50',
                'tup_deleted' => '10',
                'conflicts' => '0',
                'temp_files' => '2',
                'temp_bytes' => '1024',
                'deadlocks' => '0',
                'stats_reset' => '2024-01-01 00:00:00',
            ],
        ]);

        $result = $this->provider->fetchStatusVariables();

        $this->assertSame('3', $result['pg_stat_database.numbackends']);
        $this->assertSame('1000', $result['pg_stat_database.xact_commit']);
        $this->assertSame('5000', $result['pg_stat_database.blks_hit']);
    }

    public function testFetchStatusVariablesReturnsEmptyOnError(): void
    {
        $this->db->setQueryThrows('pg_stat_database', new \PDOException('permission denied', 42501));

        $result = $this->provider->fetchStatusVariables();

        $this->assertSame([], $result);
    }

    public function testFetchServerVariablesReturnsPgSettings(): void
    {
        $this->db->setQueryResult('pg_settings', [
            ['name' => 'max_connections', 'setting' => '200'],
            ['name' => 'shared_buffers', 'setting' => '16384'],
        ]);

        $result = $this->provider->fetchServerVariables();

        $this->assertSame('200', $result['max_connections']);
        $this->assertSame('16384', $result['shared_buffers']);
    }

    public function testFetchServerVariablesReturnsEmptyOnError(): void
    {
        $this->db->setQueryThrows('pg_settings', new \PDOException('permission denied', 42501));

        $result = $this->provider->fetchServerVariables();

        $this->assertSame([], $result);
    }

    public function testFetchProcesslistReturnsNormalizedRows(): void
    {
        $this->db->setQueryResult('pg_stat_activity', [
            [
                'pid' => 12345,
                'usename' => 'app_user',
                'client_addr' => '192.168.1.100',
                'datname' => 'myapp',
                'state' => 'active',
                'query_start' => '2024-01-01 12:00:00',
                'query' => 'SELECT * FROM orders',
                'application_name' => 'psql',
            ],
        ]);

        $result = $this->provider->fetchProcesslist();

        $this->assertCount(1, $result);
        $this->assertSame(12345, $result[0]['processId']);
        $this->assertSame('app_user', $result[0]['user']);
        $this->assertSame('192.168.1.100', $result[0]['host']);
        $this->assertSame('myapp', $result[0]['database']);
        $this->assertSame('active', $result[0]['command']);
        $this->assertGreaterThanOrEqual(0, $result[0]['time']);
        $this->assertSame('active', $result[0]['state']);
        $this->assertSame('SELECT * FROM orders', $result[0]['info']);
    }

    public function testFetchProcesslistReturnsEmptyOnError(): void
    {
        $this->db->setQueryThrows('pg_stat_activity', new \PDOException('permission denied', 42501));

        $result = $this->provider->fetchProcesslist();

        $this->assertSame([], $result);
    }

    public function testMaxConnectionsFromPgSettings(): void
    {
        $this->db->setQueryResult('pg_settings', [
            ['name' => 'max_connections', 'setting' => '300'],
        ]);

        $this->assertSame(300, $this->provider->maxConnections());
    }

    public function testMaxConnectionsDefaultsTo100WhenNotFound(): void
    {
        $this->db->setQueryResult('pg_settings', [
            ['name' => 'shared_buffers', 'setting' => '16384'],
        ]);

        $this->assertSame(100, $this->provider->maxConnections());
    }

    public function testMaxConnectionsReturns100OnError(): void
    {
        $this->db->setQueryThrows('pg_settings', new \PDOException('permission denied', 42501));

        $this->assertSame(100, $this->provider->maxConnections());
    }

    public function testStatusVariablesTsReturnsTimestamp(): void
    {
        $this->db->setQueryResult('pg_stat_database', [
            [
                'numbackends' => '1',
                'xact_commit' => '0',
                'xact_rollback' => '0',
                'blks_read' => '0',
                'blks_hit' => '0',
                'tup_returned' => '0',
                'tup_fetched' => '0',
                'tup_inserted' => '0',
                'tup_updated' => '0',
                'tup_deleted' => '0',
                'conflicts' => '0',
                'temp_files' => '0',
                'temp_bytes' => '0',
                'deadlocks' => '0',
                'stats_reset' => null,
            ],
        ]);

        $before = microtime(true);
        $this->provider->fetchStatusVariables();
        $after = microtime(true);

        $ts = $this->provider->statusVariablesTs();
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testWasResetAlwaysReturnsFalse(): void
    {
        // PostgreSQL restart detection not yet implemented
        $this->assertFalse($this->provider->wasReset());
    }

    public function testRefreshClearsCaches(): void
    {
        $this->db->setQueryResult('pg_settings', [
            ['name' => 'max_connections', 'setting' => '300'],
        ]);

        $this->provider->maxConnections();

        $this->db->setQueryResult('pg_settings', [
            ['name' => 'max_connections', 'setting' => '500'],
        ]);

        $this->provider->refresh();

        $this->assertSame(500, $this->provider->maxConnections());
    }

    public function testNewFactoryCreatesInstance(): void
    {
        $provider = PostgresAdminProvider::new($this->db);

        $this->assertInstanceOf(PostgresAdminProvider::class, $provider);
        $this->assertInstanceOf(AdminProviderInterface::class, $provider);
    }
}
