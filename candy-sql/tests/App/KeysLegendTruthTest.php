<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\ResultTable;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O16: the legends must describe the keys that are actually armed.
 *
 * The status bar and the bottom help line used to promise `Enter:load` in the
 * Rows pane, a table-preview legend while a synthetic query result was on
 * screen, and edit chords while the result buffer was empty — dead or refused
 * keys wearing live keys' clothes (once-020, once-029). These legs pin the
 * pane-honest segmentation: the load segment belongs to the Tables pane alone,
 * the Rows pane legend follows what App arms there (real preview → full edit
 * chords including the previously hidden j/k row cursor; synthetic result with
 * rows → read-and-export only; nothing under it → quit only), and an Enter in
 * the Rows pane stays the honest no-op the legend now admits.
 */
final class KeysLegendTruthTest extends TestCase
{
    /** @param array<int, array<string, string>> $rows */
    private function app(Pane $pane, BrowseState $browse = new BrowseState()): App
    {
        return new App(
            \SugarCraft\Sql\App\ConnectionState::new(new FakeDatabase(), Flavor::Sqlite),
            $browse,
            ui: new UiState($pane),
        );
    }

    /** @param array<int, array<string, string>> $rows */
    private function browseWith(array $rows, string $table): BrowseState
    {
        return (new BrowseState())
            ->withSelectedTable($table)
            ->withRows($rows)
            ->withResultTable(ResultTable::fromRows($rows));
    }

    public function testTheTablesPaneStillCarriesTheLoadSegmentAndBrowseLegend(): void
    {
        $view = $this->app(Pane::Tables, (new BrowseState())->withTables(['users', 'posts']))->view();
        $this->assertStringContainsString(Lang::t('bar.load'), $view, 'Enter is armed in Tables');
        $this->assertStringContainsString('enter  load table', $view);
    }

    public function testTheRowsPaneLegendAdvertisesTheRowCursorAndDropsTheLoadPromise(): void
    {
        $browse = $this->browseWith([['id' => '1', 'name' => 'ada']], 'users');
        $view = $this->app(Pane::Rows, $browse)->view();
        $this->assertStringContainsString('j/k  row', $view, 'the row cursor was always armed but never advertised');
        $this->assertStringContainsString('e  edit', $view);
        $this->assertStringNotContainsString(Lang::t('bar.load'), $view, 'no Enter arm exists in the Rows pane');
        $this->assertStringNotContainsString('enter  load table', $view);
    }

    public function testEnterInTheRowsPaneIsTheInertKeyTheLegendNowAdmits(): void
    {
        $browse = $this->browseWith([['id' => '1', 'name' => 'ada']], 'users');
        $app = $this->app(Pane::Rows, $browse);
        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($cmd, 'Enter must not launch anything from the Rows pane');
        $this->assertSame($browse, $next->browse, 'the preview fold must not change under a dead key');
    }

    public function testASyntheticResultLegendKeepsReadChordsAndDropsTheRefusedEditOnes(): void
    {
        $browse = $this->browseWith([['id' => '1', 'name' => 'ada']], BrowseState::SENTINEL_QUERY);
        $view = $this->app(Pane::Rows, $browse)->view();
        $this->assertStringContainsString('h/l  cell', $view, 'the cell cursor is armed over a result');
        $this->assertStringContainsString('x  export', $view);
        $this->assertStringNotContainsString('e  edit', $view, 'openEdit refuses a synthetic result');
        $this->assertStringNotContainsString('enter  load table', $view);
    }

    public function testAnEmptyRowsPanePromisesNothingButQuitting(): void
    {
        $view = $this->app(Pane::Rows, (new BrowseState())->withSelectedTable(BrowseState::SENTINEL_QUERY))->view();
        $this->assertStringContainsString('q / esc  quit', $view);
        $this->assertStringNotContainsString('x  export', $view, 'the x opener requires rows');
        $this->assertStringNotContainsString('j/k  row', $view, 'there is no cursor to move');
        $this->assertStringNotContainsString('enter  load table', $view);
    }

    public function testAnEmptyTablePreviewLegendDoesNotAdvertiseTheExportOrEditChords(): void
    {
        // Same honesty law on the real-table seam: with no rows on screen,
        // x/edit/delete refuse, so the empty legend must wear them off too.
        $browse = (new BrowseState())
            ->withSelectedTable('users')
            ->withResultTable(ResultTable::fromRows([]));
        $view = $this->app(Pane::Rows, $browse)->view();
        $this->assertStringNotContainsString('x  export', $view);
        $this->assertStringNotContainsString('e  edit', $view);
        $this->assertStringNotContainsString('enter  load table', $view);
    }

    public function testTheQueryLegendCarriesTheRunAliasAndCancelLegs(): void
    {
        $view = $this->app(Pane::Query)->view();
        $this->assertStringContainsString('ctrl+enter', $view, 'once-033: the alias was armed but never advertised here');
        $this->assertStringContainsString('cancel running', $view, 'SQL-O08 superseded the unconditional-quit prose');
    }

    public function testTheAdminLegendCarriesTheCacheDropResetAndModalCancelTruth(): void
    {
        $view = $this->app(Pane::Admin)->view();
        $this->assertStringContainsString('r  reset', $view, '[r] is armed on every admin page');
        $this->assertStringContainsString('cancel dialog', $view, 'the esc leg now names the running-query cancel too');
    }
}
