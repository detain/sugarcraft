<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\History\HistoryStoreInterface;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\StatusSnapshot;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O13: the `H` history reader.
 *
 * The always-on status store had a full query API and ZERO readers — disk
 * writes paying for a file the user could not interpret in-app. These legs
 * pin the trend fold (window read → rate rows on the synthetic-result seam),
 * the honest notes for the no-recorder (once-034: SQLite records nothing)
 * and too-short-window cases, the column-drop law for counters that are not
 * numeric across the window, the negative-rate honesty of a counter reset,
 * and the sentinel predicate that keeps table preview paging off a history
 * result. The store is fed through the PRODUCTION SqliteHistoryStore so the
 * window query rides the same retention-bounded path the app runs.
 */
final class HistoryTrendTest extends TestCase
{
    private ?string $historyPath = null;

    protected function setUp(): void
    {
        $this->historyPath = (string) tempnam(sys_get_temp_dir(), 'candysql-hist-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->historyPath !== null && is_file($this->historyPath)) {
            unlink($this->historyPath);
        }
        parent::tearDown();
    }

    private function store(): SqliteHistoryStore
    {
        return new SqliteHistoryStore($this->historyPath);
    }

    private function app(Pane $pane = Pane::Rows, string $editorText = ''): App
    {
        return App::builder()
            ->withDb(new FakeDatabase())
            ->withFlavor(Flavor::Sqlite)
            ->withPane($pane)
            ->withHistoryDbPath($this->historyPath)
            ->withQueryEditor(TextArea::new()->setValue($editorText)->focus()[0])
            ->build();
    }

    private function pressH(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'H'));
    }

    public function testTheTrendFoldsIntoTheRowsPaneThroughTheSyntheticResultSeam(): void
    {
        $now = microtime(true);
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '100', 'Com_select' => '10', 'Threads_connected' => '2'], $now - 60));
        $store->save(new StatusSnapshot(['Questions' => '400', 'Com_select' => '20', 'Threads_connected' => '3'], $now - 30));
        $store->save(new StatusSnapshot(['Questions' => '700', 'Com_select' => '30', 'Threads_connected' => '4'], $now));

        [$next, $cmd] = $this->pressH($this->app());
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $next);

        $this->assertSame(BrowseState::SENTINEL_HISTORY, $next->browse->selectedTable);
        $this->assertCount(2, $next->browse->rows, 'one row per interval, baseline excluded');
        $this->assertSame('10.00', $next->browse->rows[0]['Questions/s']);
        $this->assertSame('0.33', $next->browse->rows[0]['Com_select/s']);
        $this->assertSame('3', $next->browse->rows[0]['Threads_connected'], 'gauges read the interval end');
        $this->assertSame('10.00', $next->browse->rows[1]['Questions/s']);
        $this->assertSame(Lang::t('history.status', ['samples' => '2', 'minutes' => '15']), $next->ui->status);
        $this->assertFalse($next->browse->rowsLoading);
        $this->assertStringContainsString('(history)', $next->view(), 'the pane title names the source honestly');
    }

    public function testAStreamAbsorbsTheTrendWithoutAnEarlierSelection(): void
    {
        // Tables pane with no selection yet: the fold still answers (the
        // reader is store-bound, not selection-bound).
        $now = microtime(true);
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '0'], $now - 10));
        $store->save(new StatusSnapshot(['Questions' => '100'], $now));

        [$next,] = $this->pressH($this->app(Pane::Tables));
        $this->assertInstanceOf(App::class, $next);
        $this->assertCount(1, $next->browse->rows);
        $this->assertSame('10.00', $next->browse->rows[0]['Questions/s']);
    }

    public function testASectionWithNoStoreRecordsNothingAnsWithTheNamedNote(): void
    {
        $app = App::builder()
            ->withDb(new FakeDatabase())
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Rows)
            ->build();

        [$next, $cmd] = $this->pressH($app);
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('history.none'), $next->ui->status);
        $this->assertNull($next->browse->selectedTable, 'the note is a fold, not a fake pane');
    }

    public function testAWindowShortOnSamplesSaysSoInsteadOfRenderingNothing(): void
    {
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '5'], microtime(true) - 5));

        [$next,] = $this->pressH($this->app());
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('history.empty', ['minutes' => '15']), $next->ui->status);
        $this->assertNull($next->browse->selectedTable);
    }

    public function testSamplesOutsideTheWindowLeaveTheEmptyNote(): void
    {
        $store = $this->store();
        // Day-old rows: the 15-minute window must not resurrect them.
        $store->save(new StatusSnapshot(['Questions' => '10'], microtime(true) - 86400));
        $store->save(new StatusSnapshot(['Questions' => '20'], microtime(true) - 86000));

        [$next,] = $this->pressH($this->app());
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('history.empty', ['minutes' => '15']), $next->ui->status);
    }

    public function testACounterThatIsNotNumericAcrossTheWindowDropsItsColumn(): void
    {
        $now = microtime(true);
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '10', 'Com_select' => 'n/a', 'Threads_connected' => '1'], $now - 10));
        $store->save(new StatusSnapshot(['Questions' => '30', 'Com_select' => '2', 'Threads_connected' => '2'], $now));

        [$next,] = $this->pressH($this->app());
        $this->assertInstanceOf(App::class, $next);
        $row = $next->browse->rows[0];
        $this->assertArrayHasKey('Questions/s', $row);
        $this->assertArrayNotHasKey('Com_select/s', $row, 'a half-window counter would render a lie');
        $this->assertArrayHasKey('Threads_connected', $row);
    }

    public function testACounterResetSurfacesAsANegativeRateNotACleanZero(): void
    {
        $now = microtime(true);
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '900'], $now - 30));
        $store->save(new StatusSnapshot(['Questions' => '100'], $now));

        [$next,] = $this->pressH($this->app());
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame('-26.67', $next->browse->rows[0]['Questions/s'], 'the reset is the driver truth, shown');
    }

    public function testHistoryResultsDoNotEngageTheTablePreviewPageShift(): void
    {
        $now = microtime(true);
        $store = $this->store();
        $store->save(new StatusSnapshot(['Questions' => '10'], $now - 10));
        $store->save(new StatusSnapshot(['Questions' => '20'], $now));

        [$folded,] = $this->pressH($this->app());
        $this->assertInstanceOf(App::class, $folded);

        [$after, $cmd] = $folded->update(new KeyMsg(KeyType::PageDown));
        $this->assertInstanceOf(App::class, $after);
        $this->assertNull($cmd, 'PgDn may not dial a preview fetch for a history result');
        $this->assertSame(BrowseState::SENTINEL_HISTORY, $after->browse->selectedTable);
        $this->assertSame($folded->ui->status, $after->ui->status, 'the trend line stands — the preview pager never engages');
        $this->assertSame(0, $after->browse->pageOffset, 'no window shift on a synthetic result');
    }

    public function testTheQueryEditorKeepsTheLetter(): void
    {
        [$next,] = $this->pressH($this->app(Pane::Query, 'SELE'));
        $this->assertInstanceOf(App::class, $next);
        $this->assertStringContainsString('H', $next->editor()->value(), 'typing wins over the chord');
        $this->assertNull($next->browse->selectedTable);
    }

    public function testACorruptStoreFoldsTheNamedUnreadableLineNotAnException(): void
    {
        $broken = new class implements HistoryStoreInterface {
            public function save(StatusSnapshot $snapshot): void
            {
            }

            public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                throw new \RuntimeException('history.db is not a database');
            }

            public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
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

        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::Sqlite),
            new BrowseState(),
            ui: new \SugarCraft\Sql\App\UiState(Pane::Rows),
            admin: new \SugarCraft\Sql\App\AdminState(historyRecorder: new HistoryRecorder($broken)),
        );

        [$next, $cmd] = $this->pressH($app);
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('history.unreadable', ['message' => 'history.db is not a database']), $next->ui->error);
        $this->assertNull($next->browse->selectedTable, 'the failure may not half-fold');
    }
}
