<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * SQL-O09: a leading EXPLAIN typed in the Query editor is routed to the
 * ExplainView colour tree instead of landing as the flat provider grid —
 * and the routing must NEVER honour ANALYZE (PostgreSQL's EXPLAIN ANALYZE
 * executes the inspected statement; a plan inspector must not run what it
 * inspects) nor accept an embedded multi-statement.
 */
final class ExplainRouteTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER)');
        $this->pdo->exec('INSERT INTO t1 (id) VALUES (1), (2), (3)');
    }

    private function app(string $sql): App
    {
        return App::builder()
            ->withDb(new SqliteDatabase($this->pdo))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue($sql)->focus()[0])
            ->build();
    }

    private function pressRun(App $a): array
    {
        return $a->update(new KeyMsg(type: KeyType::Char, rune: 'r', ctrl: true));
    }

    public function testTypedExplainRendersTheColourTreeNotAFlatGrid(): void
    {
        [$a, $cmd] = $this->pressRun($this->app('EXPLAIN SELECT * FROM t1'));

        $this->assertNull($cmd);
        $this->assertNotNull($a->query->plan, 'the typed EXPLAIN must route to the plan tree');
        $this->assertStringContainsString("\x1b[", $a->query->plan, 'the tree is colour-coded, not plain text');
        $this->assertStringContainsString('SCAN', $a->query->plan);
        $this->assertSame([], $a->browse->rows, 'the flat provider grid is gone from the pane');
        $this->assertNull($a->browse->resultTable);
        $this->assertStringStartsWith('explain plan', (string) $a->ui->status);
        $this->assertStringContainsString(' explain ', $a->view(), 'the pane announces what it holds');
    }

    public function testAnalyzeModifierIsStrippedAndThePlanStillAnswers(): void
    {
        // SQLite has no 'EXPLAIN ANALYZE' grammar: had the word reached the
        // provider the leg would ERROR. A green plan therefore proves the
        // strip, and nothing was executed on the way.
        [$a, ] = $this->pressRun($this->app('EXPLAIN ANALYZE SELECT * FROM t1'));

        $this->assertNull($a->ui->error);
        $this->assertNotNull($a->query->plan, 'ANALYZE rode nowhere; the plan answers');
    }

    public function testFormatClauseModifierIsStrippedToo(): void
    {
        [$a, ] = $this->pressRun($this->app('EXPLAIN FORMAT=JSON SELECT * FROM t1'));

        $this->assertNotNull($a->query->plan, 'the providers speak their own FORMAT dialect');
    }

    public function testEmbeddedSemicolonIsNotRoutedAndNothingExtraExecutes(): void
    {
        [$a, ] = $this->pressRun($this->app('EXPLAIN SELECT * FROM t1; DROP TABLE t1'));

        $this->assertNull($a->query->plan, 'multi-statement text stays with the regular run leg and its guards');
        $exists = (bool) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 't1'")->fetchColumn();
        $this->assertTrue($exists, 'the tail statement must not reach the server through the plan path');
    }

    public function testAnyLaterQueryRunReplacesThePlan(): void
    {
        [$a, ] = $this->pressRun($this->app('EXPLAIN SELECT * FROM t1'));
        $this->assertNotNull($a->query->plan);

        // Retype the editor through the real key path: clear the drafted
        // EXPLAIN, then a plain SELECT.
        for ($i = 0; $i < 60; $i++) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Backspace, ''));
            if ($a->editor()->value() === '') {
                break;
            }
        }
        $this->assertSame('', $a->editor()->value(), 'the draft cleared via the keyboard leg');
        foreach (str_split('SELECT id FROM t1') as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertSame('SELECT id FROM t1', $a->editor()->value());

        [$b, ] = $this->pressRun($a);

        $this->assertNull($b->query->plan, 'a plain run replaces the live tree');
        $this->assertCount(3, $b->browse->rows, 'and the ordinary result lands in its place');
    }

    public function testATablePreviewReplacesThePlan(): void
    {
        [$a, ] = $this->pressRun($this->app('EXPLAIN SELECT * FROM t1'));
        $this->assertNotNull($a->query->plan);

        $loadTable = new ReflectionMethod(App::class, 'loadTable');
        $loadTable->setAccessible(true);
        /** @var App $b */
        $b = $loadTable->invoke($a, 't1');

        $this->assertNull($b->query->plan, 'the preview pane belongs to the table again');
        $this->assertNotSame([], $b->browse->rows);
    }

    public function testServerBytesInThePlanRenderSanitized(): void
    {
        $weird = "ev\x07il";
        $this->pdo->exec('CREATE TABLE "' . $weird . '" (id INTEGER)');

        [$a, ] = $this->pressRun($this->app('EXPLAIN SELECT * FROM "' . $weird . '"'));

        $this->assertNotNull($a->query->plan);
        $this->assertStringNotContainsString("\x07", $a->query->plan, 'C21 sanitization holds through the new route');
    }
}
