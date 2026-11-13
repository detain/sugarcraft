<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Tests\Support\ScriptablePdo;

/**
 * SQL-C05: connected-path coverage for MysqlDatabase.
 *
 * Before this file, every MysqlDatabase test ran with pdo=null, so the
 * query()/quote()/exec()/prepare()/tables()/rows() CONNECTED branches —
 * the ones that actually ship SQL to a server — were executed by nothing.
 * These cases run the real driver code against the ScriptablePdo replay
 * double; the opt-in live leg (CANDY_SQL_MYSQL_DSN) is the executability
 * authority cited by SQL-C03/C04/C10/P03 acceptance.
 *
 * Can-fail proof: corrupt any connected branch (e.g. make rows() drop the
 * LIMIT interpolation) and its case here goes red — previously silent.
 */
final class MysqlConnectedPathTest extends TestCase
{
    private function connect(ScriptablePdo $pdo): MysqlDatabase
    {
        $r = new \ReflectionClass(MysqlDatabase::class);
        $db = $r->newInstanceWithoutConstructor();
        $p = $r->getProperty('pdo');
        $p->setAccessible(true);
        $p->setValue($db, $pdo);
        return $db;
    }

    public function testTablesReplaysInformationSchemaUppercaseColumns(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('information_schema.tables', [['TABLE_NAME' => 'users'], ['TABLE_NAME' => 'orders']]);
        $db = $this->connect($pdo);

        // Normalizes the uppercase information_schema spelling (real MySQL).
        $this->assertSame(['users', 'orders'], $db->tables());
    }

    public function testRowsShipsLimitToTheWire(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('from `widgets`', [['id' => 1], ['id' => 2]]);
        $db = $this->connect($pdo);

        $this->assertSame([['id' => 1], ['id' => 2]], $db->rows('widgets', 42));
        $sqls = array_column(array_filter($pdo->log, fn ($e) => $e['op'] === 'query'), 'sql');
        $built = array_values(array_filter($sqls, fn ($s) => str_contains($s, 'widgets')));
        $this->assertNotEmpty($built, 'rows() must ship a SELECT for the table');
        $this->assertMatchesRegularExpression('/LIMIT 42$/', $built[0]);
    }

    public function testQueryReturnsRowSetForSelectAndAffectedForWrite(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('select id from t', [['id' => 7]]);
        $pdo->on('update t set', [], affected: 3);
        $db = $this->connect($pdo);

        $this->assertSame([['id' => 7]], $db->query('SELECT id FROM t'));
        $this->assertSame([['affected' => 3]], $db->query('UPDATE t SET x = 1'));
    }

    public function testQueryRethrowsPdoErrorsWhenNoReconnectManager(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->failing('boom', new \PDOException('server said no', 1146));
        $db = $this->connect($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('server said no');
        $db->query('BOOM sql');
    }

    public function testQuoteDelegatesToDialectAwarePdoQuote(): void
    {
        $pdo = new ScriptablePdo();
        $db = $this->connect($pdo);

        // Real mysql PDO quoting backslash-escapes; NOT plain addslashes.
        $this->assertSame("'it\\'s'", $db->quote("it's"));
        $this->assertSame("'a\\\\b'", $db->quote('a\\b'));
    }

    public function testExecShipsStatementAndCountsAffected(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('delete from t', [], affected: 5);
        $db = $this->connect($pdo);

        $this->assertSame(5, $db->exec('DELETE FROM t WHERE 0'));
        $found = array_filter($pdo->log, fn ($e) => $e['op'] === 'prepare' && str_contains($e['sql'], 'DELETE'));
        $this->assertNotEmpty($found, 'exec must prepare the statement on the wire');
    }

    public function testServerVersionReadsTheConnectedRow(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('select version()', [['ver' => '8.0.33']]);
        $db = $this->connect($pdo);

        $this->assertSame('MySQL version 8.0.33', $db->serverVersion());
    }

    public function testDatabasesFiltersThroughConnectedLeg(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('information_schema.schemata', [['schema_name' => 'app'], ['schema_name' => 'reporting']]);
        $db = $this->connect($pdo);

        $this->assertSame(['app', 'reporting'], $db->databases());
    }

    public function testPrepareReturnsBoundStatement(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('select * from t where id = ?', [['id' => 9]]);
        $db = $this->connect($pdo);

        $stmt = $db->prepare('SELECT * FROM t WHERE id = ?');
        $this->assertNotNull($stmt);
    }

    public function testLiveMysqlLegIsOptInViaEnv(): void
    {
        $dsn = getenv('CANDY_SQL_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('live leg requires CANDY_SQL_MYSQL_DSN');
        }
        $db = MysqlDatabase::connect(\SugarCraft\Sql\Db\ConnectionFactory::fromDsn($dsn));
        $this->assertTrue($db->ping());
        $this->assertStringStartsWith('MySQL version ', $db->serverVersion());
        $this->assertIsArray($db->tables());
    }
}
