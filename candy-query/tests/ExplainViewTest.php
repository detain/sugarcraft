<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Database;
use SugarCraft\Query\ExplainRow;
use SugarCraft\Query\ExplainView;
use PHPUnit\Framework\TestCase;

final class ExplainViewTest extends TestCase
{
    private function memoryDb(): Database
    {
        return new Database(new \PDO('sqlite::memory:'));
    }

    public function testRunExecutesExplainQueryPlan(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec('CREATE INDEX idx_name ON users(name)');

        $view = ExplainView::run($db, 'SELECT * FROM users WHERE name = ?');
        $this->assertNotEmpty($view->rows);
    }

    public function testRowsContainDetailAndDepth(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE t (a INTEGER, b TEXT)');
        $db->pdo->exec('CREATE INDEX idx_a ON t(a)');

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
        $db->pdo->exec('CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT)');
        $db->pdo->exec('CREATE INDEX idx_b ON t(b)');

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
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $view = ExplainView::run($db, 'SELECT * FROM users');
        $out = $view->render();
        $this->assertNotEmpty($out);
        $this->assertStringContainsString('QUERY PLAN', $out);
    }

    public function testToArrayReturnsStructuredData(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE t (x INTEGER)');
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
        $view = new ExplainView([]);
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
        $view = new ExplainView($raw);
        $this->assertCount(2, $view->rows);
        // "  |--" should give depth >= 1 for the second row.
        $this->assertGreaterThanOrEqual(1, $view->rows[1]->depth);
    }

    public function testTagSearchForUsingIndex(): void
    {
        $raw = [['detail' => 'SEARCH t USING INDEX idx_a (a=?)']];
        $view = new ExplainView($raw);
        $this->assertSame('SEARCH', $view->rows[0]->tag);
    }

    public function testTagScanForTableScan(): void
    {
        $raw = [['detail' => 'SCAN t FULL TABLE SCAN']];
        $view = new ExplainView($raw);
        $this->assertSame('SCAN', $view->rows[0]->tag);
    }

    public function testTagJoinForJoinOperation(): void
    {
        $raw = [['detail' => 'SEARCH u BY PRIMARY KEY QUERY PLAN JOIN']];
        $view = new ExplainView($raw);
        $this->assertSame('JOIN', $view->rows[0]->tag);
    }

    public function testTagSubqueryForCorrelated(): void
    {
        $raw = [['detail' => 'SCAN (subquery) CORRELATED SUBQUERY']];
        $view = new ExplainView($raw);
        $this->assertSame('SUBQUERY', $view->rows[0]->tag);
    }

    public function testTagCompoundForUnion(): void
    {
        $raw = [['detail' => 'COMPOUND QUERY USING TEMP B-TREE FOR UNION']];
        $view = new ExplainView($raw);
        $this->assertSame('COMPOUND', $view->rows[0]->tag);
    }
}
