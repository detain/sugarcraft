<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Query\Admin\PostgresServerContext;

/**
 * E718 pins for the PostgreSQL status path (pg_stat_database).
 *
 * The counts here assert the END-TO-END contract on the database handle:
 * two reads inside one CacheTtl::STATUS window cost exactly one round-trip.
 * (Both the context TTL and the provider's own memo contribute; the contract
 * the render path cares about is against the fake database, not which layer
 * satisfies it.) PostgresAdminProvider is final and degrades PDO errors
 * internally, so the context-level fail-open catch is documented as
 * defense-in-depth and carries no dedicated test.
 */
final class PostgresServerContextTest extends TestCase
{
    private FakePostgresDatabase $db;
    private PostgresServerContext $ctx;

    protected function setUp(): void
    {
        $this->db = new FakePostgresDatabase();
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
        $this->ctx = PostgresServerContext::new($this->db, PostgresAdminProvider::new($this->db));
    }

    public function testStatusVariablesInsideTtlWindowExecuteTheQueryOnce(): void
    {
        $first = $this->ctx->statusVariables();
        $second = $this->ctx->statusVariables();
        $this->ctx->statusVariablesTs();

        $this->assertSame($first, $second);
        $this->assertSame('3', $first['pg_stat_database.numbackends']);
        $this->assertSame(1, $this->db->queryCount('pg_stat_database'));
    }

    public function testRefreshForcesOneNewFetchAfterTheWindow(): void
    {
        $this->ctx->statusVariables();
        $this->ctx->refresh();
        $this->ctx->statusVariables();

        $this->assertSame(2, $this->db->queryCount('pg_stat_database'));
    }
}
