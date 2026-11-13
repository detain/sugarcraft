<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\PostgresDatabase;
use SugarCraft\Sql\Tests\Support\ScriptablePdo;

/**
 * SQL-C05: connected-path coverage for PostgresDatabase (see the MySQL twin
 * for the rationale). Live leg: CANDY_SQL_PG_DSN.
 */
final class PostgresConnectedPathTest extends TestCase
{
    private function connect(ScriptablePdo $pdo): PostgresDatabase
    {
        $r = new \ReflectionClass(PostgresDatabase::class);
        $db = $r->newInstanceWithoutConstructor();
        $p = $r->getProperty('pdo');
        $p->setAccessible(true);
        $p->setValue($db, $pdo);
        return $db;
    }

    public function testTablesReplaysCurrentSchemaQuery(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('information_schema.tables', [['table_name' => 'accounts'], ['table_name' => 'ledgers']]);
        $db = $this->connect($pdo);

        $this->assertSame(['accounts', 'ledgers'], $db->tables());
    }

    public function testRowsShipsLimitToTheWire(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('from "widgets"', [['id' => 1]]);
        $db = $this->connect($pdo);

        $this->assertSame([['id' => 1]], $db->rows('widgets', 7));
        $sqls = array_column(array_filter($pdo->log, fn ($e) => $e['op'] === 'query'), 'sql');
        $built = array_values(array_filter($sqls, fn ($s) => str_contains($s, 'widgets')));
        $this->assertMatchesRegularExpression('/LIMIT 7$/', $built[0]);
    }

    public function testQueryReturnsRowsAndAffected(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('select id from t', [['id' => 3]]);
        $pdo->on('insert into t', [], affected: 2);
        $db = $this->connect($pdo);

        $this->assertSame([['id' => 3]], $db->query('SELECT id FROM t'));
        $this->assertSame([['affected' => 2]], $db->query('INSERT INTO t VALUES (1),(2)'));
    }

    public function testQuoteDoublesSingleQuotesLikeRealPgPdo(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $db = $this->connect($pdo);

        $this->assertSame("'it''s'", $db->quote("it's"));
    }

    public function testServerVersionReadsVersionRow(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('select version()', [['version' => 'PostgreSQL 16.3 on x86_64']]);
        $db = $this->connect($pdo);

        $this->assertSame('PostgreSQL PostgreSQL 16.3 on x86_64', $db->serverVersion());
    }

    public function testDatabasesReadsPgDatabase(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('pg_database', [['datname' => 'app'], ['datname' => 'warehouse']]);
        $db = $this->connect($pdo);

        $this->assertSame(['app', 'warehouse'], $db->databases());
    }

    public function testExecPreparesAndCounts(): void
    {
        $pdo = new ScriptablePdo('pgsql');
        $pdo->on('update t set', [], affected: 4);
        $db = $this->connect($pdo);

        $this->assertSame(4, $db->exec('UPDATE t SET x = 1'));
    }

    public function testLivePostgresLegIsOptInViaEnv(): void
    {
        $dsn = getenv('CANDY_SQL_PG_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('live leg requires CANDY_SQL_PG_DSN');
        }
        $db = PostgresDatabase::connect(ConnectionFactory::fromDsn($dsn));
        $this->assertTrue($db->ping());
        $this->assertStringStartsWith('PostgreSQL ', $db->serverVersion());
        $this->assertIsArray($db->tables());
    }
}
