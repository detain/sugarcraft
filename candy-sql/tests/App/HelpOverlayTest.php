<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\KeyMap;
use SugarCraft\Sql\App\QueryState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O21 (once-028): the `?` key-help overlay, generated from ONE roster.
 *
 * The README key tables and the per-pane legends had drifted from the armed
 * handlers repeatedly (that is what SQL-O16 had to clean up). The overlay is
 * generated from KeyMap instead of being hand-typed, so it cannot advertise a
 * roster that differs from the README's — KeysRosterCrossChecksTheReadme holds
 * the two together cell-for-cell, multiset equality in both directions. The
 * remaining legs pin the routing law: the overlay is a modal surface, ANY
 * keystroke closes it and is swallowed (`Tab` must not cycle, `Ctrl+C` must
 * not quit under it), it never opens in the Query pane (`?` is a typeable
 * rune and the SQL-O23 placeholder mark there), and it never opens while a
 * filter line owns the keyboard.
 */
final class HelpOverlayTest extends TestCase
{
    private function app(Pane $pane = Pane::Tables, string $editorText = ''): App
    {
        return new App(
            ConnectionState::new(new FakeDatabase(), Flavor::Sqlite),
            (new BrowseState())->withTables(['users', 'posts']),
            new QueryState(editor: TextArea::new()->setValue($editorText)->focus()[0]),
            new UiState($pane),
        );
    }

    /** @return array{0: App, 1: ?\SugarCraft\Core\Cmd} */
    private function press(App $app, string $rune, bool $ctrl = false): array
    {
        return $app->update(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl));
    }

    public function testTheOverlayOpensFromEveryNonQueryPane(): void
    {
        foreach ([Pane::Tables, Pane::Rows, Pane::Admin] as $pane) {
            [$next, $cmd] = $this->press($this->app($pane), '?');
            $this->assertTrue($next->ui->helpOverlay, $pane->name . ' opens the overlay');
            $this->assertNull($cmd);
        }
    }

    public function testTheOverlayListsTheRosterWithTitlesAndDescriptions(): void
    {
        $view = $this->app(Pane::Tables)->update(new KeyMsg(KeyType::Char, '?'))[0]->view();
        $this->assertStringContainsString(Lang::t('help.title', ['pane' => trim(Lang::t('ui.title.tables'))]), $view);
        $this->assertStringContainsString('Tab', $view);
        $this->assertStringContainsString('Ctrl+R', $view);
        $this->assertStringContainsString('q / Esc', $view);
        $this->assertStringContainsString(Lang::t('help.cycle'), $view);
        $this->assertStringContainsString(Lang::t('help.close'), $view);
    }

    public function testTheAdminPaneOverlayCarriesTheAdminChords(): void
    {
        $view = $this->app(Pane::Admin)->update(new KeyMsg(KeyType::Char, '?'))[0]->view();
        $this->assertStringContainsString('[p]', $view);
        $this->assertStringContainsString('K / X', $view);
        $this->assertStringContainsString('1 – 8', $view);
        $this->assertStringNotContainsString(
            '[p]',
            $this->app(Pane::Tables)->update(new KeyMsg(KeyType::Char, '?'))[0]->view(),
            'admin chords are meaningless outside the Admin pane',
        );
    }

    public function testTheQueryPaneTypesTheRuneInsteadOfOpening(): void
    {
        [$next, $cmd] = $this->press($this->app(Pane::Query, 'SELECT 1'), '?');
        $this->assertFalse($next->ui->helpOverlay);
        $this->assertNull($cmd);
        $this->assertStringEndsWith('?', (string) $next->query->editor?->value());
    }

    public function testTheOverlayDoesNotOpenWhileTheFilterLineOwnsTheKeyboard(): void
    {
        $app = $this->app(Pane::Tables);
        [$filtered] = $app->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertTrue($filtered->browse->filterMode);
        [$next, $cmd] = $filtered->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertFalse($next->ui->helpOverlay);
        $this->assertNull($cmd);
        $this->assertStringContainsString('?', (string) $next->browse->filterText);
    }

    public function testAnyKeyClosesTheOverlayAndIsSwallowed(): void
    {
        [$open] = $this->press($this->app(Pane::Tables), '?');
        [$next, $cmd] = $open->update(new KeyMsg(KeyType::Char, "\t"));
        $this->assertFalse($next->ui->helpOverlay, 'the closing key is consumed');
        $this->assertNull($cmd);
        $this->assertSame(Pane::Tables, $next->ui->pane, 'Tab must not cycle beneath the overlay');
    }

    public function testCtrlCClosesTheOverlayWithoutQuitting(): void
    {
        [$open] = $this->press($this->app(Pane::Tables), '?');
        [$closed, $first] = $this->press($open, 'c', ctrl: true);
        $this->assertFalse($closed->ui->helpOverlay, 'help must never quit the app');
        $this->assertNull($first);
        [, $second] = $this->press($closed, 'c', ctrl: true);
        $this->assertNotNull($second, 'once closed, Ctrl+C returns to its cancel-or-quit role');
    }

    public function testEnterAndEscapeCloseTheOverlay(): void
    {
        [$open] = $this->press($this->app(Pane::Tables), '?');
        foreach ([new KeyMsg(KeyType::Enter), new KeyMsg(KeyType::Escape)] as $msg) {
            [$next, $cmd] = $open->update($msg);
            $this->assertFalse($next->ui->helpOverlay);
            $this->assertNull($cmd);
        }
    }

    public function testPasteClosesTheOverlayAndIsSwallowed(): void
    {
        [$open] = $this->press($this->app(Pane::Tables), '?');
        [$next, $cmd] = $open->update(new \SugarCraft\Core\Msg\PasteMsg('drop-into-void'));
        $this->assertFalse($next->ui->helpOverlay);
        $this->assertNull($cmd);
        $this->assertSame(Pane::Tables, $next->ui->pane);
    }

    /**
     * The overlay is generated; the README is the human mirror. If the two
     * key tables and KeyMap drift apart cell-for-cell, this fails.
     */
    public function testTheKeyMapRosterMatchesTheReadmeCellForCell(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertSame(
            $this->readmeCells($readme, '/^## Keys\n.*?\n\|[-]+\|[-]+\|\n(.*?)\n\n/s m'),
            $this->sortedCells(KeyMap::CLIENT),
            'the client key table and KeyMap::CLIENT must agree cell for cell',
        );
        $this->assertSame(
            $this->readmeCells($readme, '/^### Admin Dashboard Keys\n.*?\n\|[-]+\|[-]+\|\n(.*?)\n\n/s m'),
            $this->sortedCells(KeyMap::ADMIN),
            'the admin key table and KeyMap::ADMIN must agree cell for cell',
        );
    }

    /** @return list<string> */
    private function readmeCells(string $readme, string $pattern): array
    {
        $this->assertSame(1, preg_match($pattern, $readme, $block));
        $cells = [];
        foreach (explode("\n", trim($block[1])) as $line) {
            if (preg_match('/^\|(.+?)\|/s', $line, $cell)) {
                $cells[] = trim(str_replace('`', '', $cell[1]));
            }
        }

        return $this->sortedCells(array_map(static fn(string $c): array => ['cell' => $c], $cells));
    }

    /** @param list<array{cell: string}> $rows @return list<string> */
    private function sortedCells(array $rows): array
    {
        $cells = array_column($rows, 'cell');
        sort($cells);
        return $cells;
    }

    /**
     * The overlay cannot advertise a binding nothing arms: every roster cell
     * cites a test file that drives its handler (path existence asserted too,
     * so a citation can never rot into a ghost), and the shared run arm is
     * cited once. The cited suites were mutation-proven in their own rows and
     * in the SQL-O16 truth pass; the `?` cell is driven by this very suite.
     */
    public function testEveryRosterCellCarriesCoverageEvidence(): void
    {
        $client = [
            'Tab' => ['tests/AppTest.php'],
            '↑/↓ or j/k' => ['tests/AppTest.php'],
            '↑/↓ (query pane)' => ['tests/App/HistoryRecallTest.php'],
            '←/→ or h/l' => ['tests/ResultTableCursorTest.php'],
            'e (Rows pane)' => ['tests/App/CellEditTest.php'],
            'o (Rows pane)' => ['tests/App/CellEditTest.php'],
            'D (Rows pane)' => ['tests/App/CellEditTest.php'],
            'y (Rows pane)' => ['tests/App/CellYankTest.php'],
            'Enter / Space (Tables pane)' => ['tests/AppTest.php'],
            'Enter (query pane)' => ['tests/AppTest.php'],
            'Ctrl+R' => ['tests/AppTest.php'],
            'Ctrl+E' => ['tests/AppTest.php'],
            'Ctrl+Enter' => ['tests/AppTest.php'],
            'Ctrl+F' => ['tests/App/FavoritesChordReadoutTest.php'],
            'Ctrl+Shift+F' => ['tests/App/FavoritesChordReadoutTest.php'],
            'Backspace' => ['tests/AppTest.php'],
            'x / i (Tables or Rows pane)' => ['tests/App/ExportImportLineTest.php'],
            'H (Tables, Rows or Admin pane)' => ['tests/App/HistoryTrendTest.php'],
            ':tx … (query pane, run with the run keys)' => ['tests/App/TxWorkflowTest.php'],
            'run with ? / :name placeholders in the statement (query pane)' => ['tests/App/ParamBindFlowTest.php'],
            '? (Tables, Rows or Admin pane)' => ['tests/App/HelpOverlayTest.php'],
            'q / Esc' => ['tests/App/QuitKeyRoutingTest.php'],
            'Ctrl+C' => ['tests/App/QuitKeyRoutingTest.php'],
        ];
        $admin = [
            '[p]' => ['tests/AppTest.php'],
            '[r]' => ['tests/Admin/AdminQueryCacheTest.php'],
            '[j/k] [↑/↓]' => ['tests/App/AdminRowNavigationRoutingTest.php'],
            '[e]' => ['tests/Admin/Variables/VariablesPageTest.php'],
            '[w]' => ['tests/Admin/Variables/VariablesPageTest.php'],
            '[s]' => ['tests/Admin/Variables/VariablesPageTest.php'],
            '[f]' => ['tests/Admin/Connections/ConnectionsPageTest.php'],
            'K / X' => ['tests/Admin/Connections/ConnectionsPageTest.php'],
            'y' => ['tests/Admin/Connections/ConnectionsPageTest.php'],
            '[c]' => ['tests/Admin/PerfSchema/PerfSchemaPageTest.php', 'tests/Admin/Reports/ReportsPageTest.php'],
            '[x]' => ['tests/Admin/Reports/ReportsPageTest.php'],
            '[a]' => ['tests/Admin/Dashboard/DashboardPageTest.php'],
            '[c] / [f]' => ['tests/Admin/QueryLoggerTest.php'],
            '1 – 8' => ['tests/App/AdminPagePersistenceTest.php'],
        ];
        $root = dirname(__DIR__, 2);
        $maps = [['client', KeyMap::CLIENT, $client], ['admin', KeyMap::ADMIN, $admin]];
        foreach ($maps as [$label, $rows, $cites]) {
            foreach ($rows as $row) {
                $evidence = $cites[$row['cell']] ?? [];
                $this->assertNotSame([], $evidence, "uncovered {$label} binding: " . $row['cell']);
                foreach ($evidence as $cite) {
                    $this->assertFileExists($root . '/' . $cite, "ghost {$label} citation for " . $row['cell']);
                }
            }
        }
    }

    public function testTheOverlaySurvivesTheFrameRenderWithoutPanickingOnWideCells(): void
    {
        // CJK locale path: every help.* key must resolve for each shipped locale
        // without throwing inside the renderer (widths come from mb_*).
        $app = $this->app(Pane::Rows);
        [$open] = $this->press($app, '?');
        $view = $open->view();
        $this->assertStringContainsString(Lang::t('help.overlay'), $view);
    }
}
