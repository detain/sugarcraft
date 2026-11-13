<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use SugarCraft\Sql\Database;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Explain\ExplainProviderInterface;
use SugarCraft\Sql\Explain\MysqlExplainProvider;
use SugarCraft\Sql\ExplainRow;
use SugarCraft\Sql\ExplainView;
use SugarCraft\Sql\Tests\Support\ScriptablePdo;
use PHPUnit\Framework\TestCase;

final class ExplainViewTest extends TestCase
{
    private function memoryDb(): Database
    {
        return new Database(new \PDO('sqlite::memory:'));
    }

    /** Wire a connected MysqlDatabase over the replay double (C05 bed). */
    private function mysqlDb(ScriptablePdo $pdo): MysqlDatabase
    {
        $r = new \ReflectionClass(MysqlDatabase::class);
        $db = $r->newInstanceWithoutConstructor();
        $p = $r->getProperty('pdo');
        $p->setValue($db, $pdo);
        return $db;
    }

    public function testRunAgainstDecoratedMysqlServerPicksTheMysqlProvider(): void
    {
        // SQL-C03 (client-003): serverVersion() answers the worded
        // 'MySQL version 8.0.33'; the flavor pick must stay in the MySQL
        // family and ship `EXPLAIN FORMAT=JSON` — never EXPLAIN QUERY PLAN.
        $pdo = new ScriptablePdo();
        $pdo->on('VERSION', [['ver' => '8.0.33']]);
        $pdo->on('EXPLAIN', []);
        $db = $this->mysqlDb($pdo);

        $view = ExplainView::run($db, 'SELECT * FROM users');

        $flavor = (new \ReflectionProperty(ExplainView::class, 'flavor'))->getValue($view);
        $provider = (new \ReflectionProperty(ExplainView::class, 'provider'))->getValue($view);
        $this->assertNotSame(Flavor::Sqlite, $flavor);
        $this->assertSame(Flavor::MySQL, $flavor);
        $this->assertInstanceOf(MysqlExplainProvider::class, $provider);
        $sent = array_column(
            array_filter($pdo->log, static fn (array $e): bool => $e['op'] === 'query' || $e['op'] === 'prepare'),
            'sql',
        );
        $this->assertFalse(
            (bool) preg_grep('/QUERY PLAN/', $sent),
            'mysql driver must never receive EXPLAIN QUERY PLAN',
        );
        $this->assertTrue(
            (bool) array_filter($sent, static fn (string $s): bool => str_contains($s, 'EXPLAIN FORMAT=JSON')),
            'expected the MySQL explain wire EXPLAIN FORMAT=JSON, saw: ' . implode(' | ', $sent),
        );
    }

    public function testRunExecutesExplainQueryPlan(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE INDEX idx_name ON users(name)');

        $view = ExplainView::run($db, 'SELECT * FROM users WHERE name = ?');
        $this->assertNotEmpty($view->rows);
    }

    public function testRowsContainDetailAndDepth(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE t (a INTEGER, b TEXT)');
        $db->exec('CREATE INDEX idx_a ON t(a)');

        $view = ExplainView::run($db, 'SELECT * FROM t WHERE a = 1');
        foreach ($view->rows as $row) {
            $this->assertInstanceOf(ExplainRow::class, $row);
            $this->assertNotEmpty($row->detail);
            $this->assertGreaterThanOrEqual(0, $row->depth);
            $this->assertNotEmpty($row->tag);
        }
    }

    public function testTagIsClassifiedCorrectly(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT)');
        $db->exec('CREATE INDEX idx_b ON t(b)');

        $view = ExplainView::run($db, 'SELECT * FROM t WHERE b = ?');

        // INDEXed SEARCH operations should be tagged as SEARCH.
        $tags = array_column($view->rows, 'tag');
        $hasSearch = in_array('SEARCH', $tags, true);
        $hasScan = in_array('SCAN', $tags, true);
        $this->assertTrue($hasSearch || $hasScan, 'Expected at least SEARCH or SCAN tag');
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $view = ExplainView::run($db, 'SELECT * FROM users');
        $out = $view->render();
        $this->assertNotEmpty($out);
        $this->assertStringContainsString('QUERY PLAN', $out);
    }

    public function testToArrayReturnsStructuredData(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE t (x INTEGER)');
        $view = ExplainView::run($db, 'SELECT * FROM t');

        $arr = $view->toArray();
        $this->assertIsArray($arr);
        if ($arr !== []) {
            $this->assertArrayHasKey('depth', $arr[0]);
            $this->assertArrayHasKey('tag', $arr[0]);
            $this->assertArrayHasKey('detail', $arr[0]);
            $this->assertArrayHasKey('indent', $arr[0]);
        }
    }

    public function testEmptyResultSetShowsPlaceholder(): void
    {
        // Construct with empty raw data to test placeholder rendering.
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView([], Flavor::Sqlite, $provider);
        $out = $view->render();
        $this->assertStringContainsString('no query plan', $out);
    }

    public function testDepthIsParsedFromPipeNotation(): void
    {
        // Directly test the ExplainView with a known raw detail that has nesting.
        $raw = [
            ['detail' => 'SEARCH t USING INDEX idx_a'],
            ['detail' => '  |--SEARCH t USING COVERING INDEX idx_a'],
        ];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertCount(2, $view->rows);
        // "  |--" should give depth >= 1 for the second row.
        $this->assertGreaterThanOrEqual(1, $view->rows[1]->depth);
    }

    public function testTagSearchForUsingIndex(): void
    {
        $raw = [['detail' => 'SEARCH t USING INDEX idx_a (a=?)']];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertSame('SEARCH', $view->rows[0]->tag);
    }

    public function testTagScanForTableScan(): void
    {
        $raw = [['detail' => 'SCAN t FULL TABLE SCAN']];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertSame('SCAN', $view->rows[0]->tag);
    }

    public function testTagJoinForJoinOperation(): void
    {
        $raw = [['detail' => 'SEARCH u BY PRIMARY KEY QUERY PLAN JOIN']];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertSame('JOIN', $view->rows[0]->tag);
    }

    public function testTagSubqueryForCorrelated(): void
    {
        $raw = [['detail' => 'SCAN (subquery) CORRELATED SUBQUERY']];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertSame('SUBQUERY', $view->rows[0]->tag);
    }

    public function testTagCompoundForUnion(): void
    {
        $raw = [['detail' => 'COMPOUND QUERY USING TEMP B-TREE FOR UNION']];
        $provider = $this->createMock(ExplainProviderInterface::class);
        $view = new ExplainView($raw, Flavor::Sqlite, $provider);
        $this->assertSame('COMPOUND', $view->rows[0]->tag);
    }
}
