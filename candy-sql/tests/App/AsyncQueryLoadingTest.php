<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Core\Msg\QueryRowsLoadedMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;

/**
 * Pins for the loading-as-empty defect (review C3, 2026-11-03): an async
 * query run must paint 'loading…' on the Rows pane, never "(empty)" —
 * and the result/error folds must clear the flag so the spinner cannot
 * wedge. SQLite (sync) never arms the flag at all.
 */
final class AsyncQueryLoadingTest extends TestCase
{
    private const SQL = 'SELECT 1';

    private function asyncApp(): App
    {
        // MySQL flavor over a doubled handle: sslMode falls to '' (the db is
        // not a MysqlDatabase), so the TLS door stays open and beginRunQuery
        // takes the async arm. The promise Cmd is only RETURNED, never run —
        // nothing dials.
        return App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::MySQL)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue(self::SQL))
            ->build();
    }

    public function testTheAsyncRunArmsTheSpinnerAndPaintsLoading(): void
    {
        [$model, $cmd] = $this->asyncApp()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        self::assertNotNull($cmd, 'the async arm must hand back a promise command');
        self::assertTrue($model->browse->rowsLoading, 'a query in flight must set rowsLoading');

        $frame = Renderer::render($model);
        self::assertStringContainsString('loading…', $frame);
        self::assertStringNotContainsString('(empty)', $frame, 'in-flight queries must not look like zero-row results');
    }

    public function testTheResultFoldClearsTheSpinner(): void
    {
        [$model] = $this->asyncApp()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $folded = $model->update(new QueryRowsLoadedMsg(self::SQL, [['a' => 1]], null, $model->query->queryGen))[0];

        self::assertFalse($folded->browse->rowsLoading);
        self::assertCount(1, $folded->browse->rows);
        self::assertNotNull($folded->browse->resultTable);
    }

    public function testTheErrorFoldClearsTheSpinner(): void
    {
        [$model] = $this->asyncApp()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $folded = $model->update(new QueryRowsLoadedMsg(self::SQL, [], 'boom', $model->query->queryGen))[0];

        self::assertFalse($folded->browse->rowsLoading, 'a failed run must not leave the spinner armed');
        self::assertSame('boom', $folded->ui->error);
    }

    public function testAStaleGenerationFoldLeavesTheInFlightSpinnerAlone(): void
    {
        // SQL-C12/client-012: the dispatch stamp is the staleness authority.
        // Run the SAME statement twice (the SELECT → write → re-SELECT shape
        // the old SQL-text compare could not tell apart) and let the FIRST
        // leg settle late: it carries a superseded generation and must drop,
        // leaving the current run's spinner armed for its own fold.
        [$model, ] = $this->asyncApp()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $staleGen = $model->query->queryGen;
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $folded = $model->update(new QueryRowsLoadedMsg(self::SQL, [['a' => 1]], null, $staleGen))[0];

        self::assertTrue($folded->browse->rowsLoading, 'the superseded leg must not settle the live spinner');
        self::assertSame([], $folded->browse->rows, 'pre-run rows from the stale leg stay dropped');
    }

    public function testTheSyncPathNeverArmsTheSpinner(): void
    {
        $app = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue(self::SQL))
            ->build();

        [$model, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        self::assertNull($cmd, 'SQLite runs synchronously — no pending command');
        self::assertFalse($model->browse->rowsLoading);
    }
}
