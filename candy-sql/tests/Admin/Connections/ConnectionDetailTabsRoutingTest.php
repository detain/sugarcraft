<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\View;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\AdminState;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\MouseScopedApp;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * The Connections detail tabs on the sugar-widgets Tabs bar, and the
 * AUD §2 #19 regression: the detail-tab keys (Tab / 1 / 2 / 3) used to be
 * unreachable in the app, because App consumed Tab for the pane cycle and
 * the digits for the page picker before the page ever saw them.
 *
 * Enter now focuses the bar; while it is focused the page claims Tab,
 * Shift+Tab and 1-3 ahead of those app chords, and Esc hands them back.
 */
final class ConnectionDetailTabsRoutingTest extends TestCase
{
    private const LABELS = ['Details', 'Attributes', 'MDL'];

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
    }

    private function page(): ConnectionsPage
    {
        $db = new FakeDatabase();
        return ConnectionsPage::new(new ServerContext($db));
    }

    /** An app on the Admin pane with the Connections page pinned into state. */
    private function adminApp(): App
    {
        $db = new FakeDatabase();
        $a = new App(ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)));
        // Tables → Rows → Query → Admin
        for ($i = 0; $i < 3; $i++) {
            [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        }
        $this->assertSame(Pane::Admin, $a->ui->pane);
        $this->assertSame(AdminPane::ProcessList, $a->admin->pane);

        return $a;
    }

    private function connections(App $a): ConnectionsPage
    {
        $page = $a->admin->page;
        $this->assertInstanceOf(ConnectionsPage::class, $page);
        return $page;
    }

    // ── (a) rendering through the Tabs library ─────────────────────────────

    public function testTheDetailTabsAreAnInteractiveTabsBarWithTheDocumentedLabels(): void
    {
        $bar = $this->page()->detailTabBar();

        $this->assertInstanceOf(Tabs::class, $bar);
        $this->assertSame(self::LABELS, $bar->labels());
        $this->assertNotNull($bar->mark, 'built with Tabs::interactive($labels, Mark)');
        $this->assertSame(0, $bar->active());
        $this->assertFalse($bar->focused, 'the bar starts blurred, like every bar');
        $this->assertStringContainsString(Sanitize::ZONE_SENTINEL_OPEN, $bar->view(), 'the bar publishes Mark zones');
    }

    public function testThePageRendersTheBarWithoutZoneMarkers(): void
    {
        $page = $this->page();
        $view = $page->view();

        $this->assertStringNotContainsString(Sanitize::ZONE_SENTINEL_OPEN, $view, 'the painted frame never carries markers');
        $this->assertStringContainsString(' ' . Scan::strip($page->detailTabBar()->view()), $view);
        $plain = Scan::strip(\preg_replace('/\e\[[0-9;]*m/', '', $view) ?? '');
        $this->assertStringContainsString('Details │ Attributes │ MDL', $plain);
    }

    public function testFocusUnderlinesTheActiveTab(): void
    {
        [$focused] = $this->page()->update(new KeyMsg(KeyType::Enter));

        $this->assertTrue($focused->detailTabsFocused());
        $this->assertNotSame($this->page()->detailTabBar()->view(), $focused->detailTabBar()->view());
    }

    // ── (b) every detail-tab key reaches the tab switch through App ─────────

    public function testEveryDetailTabKeyReachesTheTabSwitchOnceTheBarIsFocused(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($this->connections($a)->detailTabsFocused());

        $steps = [
            [new KeyMsg(KeyType::Tab), ConnectionsPage::DETAIL_TAB_ATTRIBUTES],
            [new KeyMsg(KeyType::Tab), ConnectionsPage::DETAIL_TAB_MDL],
            [new KeyMsg(KeyType::Tab), ConnectionsPage::DETAIL_TAB_DETAILS],
            [new KeyMsg(KeyType::Tab, shift: true), ConnectionsPage::DETAIL_TAB_MDL],
            [new KeyMsg(KeyType::Char, '1'), ConnectionsPage::DETAIL_TAB_DETAILS],
            [new KeyMsg(KeyType::Char, '2'), ConnectionsPage::DETAIL_TAB_ATTRIBUTES],
            [new KeyMsg(KeyType::Char, '3'), ConnectionsPage::DETAIL_TAB_MDL],
        ];
        foreach ($steps as $n => [$key, $want]) {
            [$a, $cmd] = $a->update($key);
            $this->assertSame($want, $this->connections($a)->detailTab(), "step {$n}: {$key->string()}");
            $this->assertNull($cmd, "step {$n}: a claimed key starts no admin fetch");
            $this->assertSame(Pane::Admin, $a->ui->pane, "step {$n}: Tab must not cycle the pane");
            $this->assertSame(AdminPane::ProcessList, $a->admin->pane, "step {$n}: a digit must not pick a page");
        }
    }

    public function testABlurredBarLeavesTabAndTheDigitsToTheApp(): void
    {
        $a = $this->adminApp();
        $this->assertFalse($a->adminPage() instanceof ConnectionsPage && $a->adminPage()->claimsKey(new KeyMsg(KeyType::Tab)));

        [$digit,] = $a->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(AdminPane::Variables, $digit->admin->pane, 'the page picker keeps 2');

        [$tab,] = $a->update(new KeyMsg(KeyType::Tab));
        $this->assertNotSame(Pane::Admin, $tab->ui->pane, 'Tab keeps cycling the pane');
    }

    public function testEscReleasesTheTabKeysBackToTheApp(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Enter));
        [$a,] = $a->update(new KeyMsg(KeyType::Char, '3'));
        [$a,] = $a->update(new KeyMsg(KeyType::Escape));

        $this->assertFalse($this->connections($a)->detailTabsFocused());
        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $this->connections($a)->detailTab(), 'Esc keeps the chosen tab');

        [$digit,] = $a->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(AdminPane::Variables, $digit->admin->pane);
    }

    public function testAnOpenExportPathLineOutranksTheFocusedBarsDigitClaim(): void
    {
        $db = new FakeDatabase();
        $a = new App(
            ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)),
            BrowseState::new()
                ->withTables(['posts'])
                ->withSelectedTable('posts')
                ->withRows([['id' => 1]]),
        );
        for ($i = 0; $i < 3; $i++) {
            [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        }
        $this->assertSame(Pane::Admin, $a->ui->pane);
        [$a,] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($this->connections($a)->detailTabsFocused());

        [$a,] = $a->update(new KeyMsg(type: KeyType::Char, rune: 's', ctrl: true));
        $this->assertTrue($a->io->isPromptOpen(), 'Ctrl+S raises the export line on the Admin pane');

        foreach (mb_str_split('ex2.csv') as $ch) {
            [$a,] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }

        $this->assertSame('ex2.csv', $a->io->buffer, 'the digit belongs to the path, not the bar');
        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $this->connections($a)->detailTab(), 'the detail tab does not switch');
    }

    public function testTheFocusedBarClaimsOnlyItsOwnKeys(): void
    {
        [$page] = $this->page()->update(new KeyMsg(KeyType::Enter));

        $this->assertTrue($page->claimsKey(new KeyMsg(KeyType::Tab)));
        $this->assertTrue($page->claimsKey(new KeyMsg(KeyType::Tab, shift: true)));
        foreach (['1', '2', '3'] as $digit) {
            $this->assertTrue($page->claimsKey(new KeyMsg(KeyType::Char, $digit)), $digit);
        }
        foreach (['0', '4', '8', 'q', 'p', 'r', 'j'] as $rune) {
            $this->assertFalse($page->claimsKey(new KeyMsg(KeyType::Char, $rune)), "{$rune} stays app-level");
        }
        $this->assertFalse($page->claimsKey(new KeyMsg(KeyType::Char, '2', ctrl: true)));
    }

    public function testOtherAdminChordsKeepWorkingWhileTheBarIsFocused(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Enter));

        [$picked,] = $a->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(AdminPane::orderedCases()[3], $picked->admin->pane, 'digits past the tab count still pick pages');

        [$back,] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame(Pane::Tables, $back->ui->pane, 'q still returns to Tables');
    }

    public function testAPendingKillStillOwnsEveryKey(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            // SHOW-style row: FakeDatabase never reports @@performance_schema = 1.
            ['Id' => '7', 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'SELECT 1'],
        ]);
        $page = ConnectionsPage::new(new ServerContext($db));
        [$page] = $page->update(new KeyMsg(KeyType::Enter));
        [$page] = $page->update(new KeyMsg(KeyType::Char, 'K'));
        $this->assertTrue($page->isModal());
        $this->assertFalse($page->claimsKey(new KeyMsg(KeyType::Tab)), 'the kill prompt, not the bar, owns the keyboard');

        [$page] = $page->update(new KeyMsg(KeyType::Tab));
        $this->assertFalse($page->isModal(), 'any non-y key cancels the kill');
        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $page->detailTab(), 'the cancelling key does not also switch tabs');
    }

    // ── (c) a mouse click on a tab zone switches to that tab ────────────────

    /** @return array{int, int} page-relative 1-based (col, row) inside tab $index */
    private function tabCell(ConnectionsPage $page, int $index): array
    {
        $lines = \explode("\n", Scan::strip($page->view()));
        $bar = $page->detailTabBar();
        $barLine = ' ' . Scan::strip($bar->view());
        $row = \array_search($barLine, $lines, true);
        $this->assertIsInt($row, 'the bar line is in the page frame');
        $span = $bar->spans()[$index];
        // +1 for the leading space, +1 for the Scanner's 1-based columns.
        return [$span->start + 2, $row + 1];
    }

    public function testAClickOnATabZoneSwitchesToThatTab(): void
    {
        $page = $this->page();

        foreach ([2 => ConnectionsPage::DETAIL_TAB_MDL, 1 => ConnectionsPage::DETAIL_TAB_ATTRIBUTES, 0 => ConnectionsPage::DETAIL_TAB_DETAILS] as $index => $want) {
            [$col, $row] = $this->tabCell($page, $index);
            [$page, $cmd] = $page->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
            $this->assertSame($want, $page->detailTab(), "click on tab {$index}");
            $this->assertNull($cmd);
        }
        $this->assertFalse($page->detailTabsFocused(), 'a click never grants keyboard focus');
    }

    public function testTheClickLandsOnTheSameZoneTheScannerSees(): void
    {
        $page = $this->page();
        [$col, $row] = $this->tabCell($page, 1);
        $marked = ' ' . $page->detailTabBar()->view();
        $zone = Scanner::new()->scan($marked)->hit($col, 1);

        $this->assertNotNull($zone);
        $this->assertSame($page->detailTabBar()->zoneKey . 'tab-1', $zone->id);
        $this->assertGreaterThan(1, $row);
    }

    public function testAMissOrANonLeftPressLeavesThePageUnchanged(): void
    {
        $page = $this->page();
        [$col, $row] = $this->tabCell($page, 2);

        $this->assertSame($page, $page->update(new MouseClickMsg($col, $row + 1, MouseButton::Left, MouseAction::Press))[0]);
        $this->assertSame($page, $page->update(new MouseClickMsg($col, $row, MouseButton::Right, MouseAction::Press))[0]);
        $this->assertSame($page, $page->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Release))[0]);
    }
    // ── (c, in the app) a click on the painted screen reaches the bar ───────

    /** @return array{int, int} 1-based screen (col, row) of $label in the painted bar */
    private function screenCell(App $a, string $label): array
    {
        $plain = Scan::strip(\preg_replace('/\e\[[0-9;]*m/', '', $a->view()) ?? '');
        foreach (\explode("\n", $plain) as $i => $line) {
            if (\str_contains($line, 'Details │ Attributes │ MDL')) {
                $at = \mb_strpos($line, 'Details │ Attributes │ MDL');
                $offset = \mb_strpos(\mb_substr($line, $at), $label);
                return [$at + $offset + 1, $i + 1];
            }
        }
        $this->fail('the painted screen shows the detail-tab bar');
    }

    public function testAClickOnThePaintedScreenSwitchesTheTabThroughApp(): void
    {
        Renderer::setSize(160, 50);
        $a = $this->adminApp();

        [$col, $row] = $this->screenCell($a, 'MDL');
        [$a, $cmd] = $a->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $this->connections($a)->detailTab());
        $this->assertNull($cmd);
        $this->assertSame(Pane::Admin, $a->ui->pane);

        [$col, $row] = $this->screenCell($a, 'Attributes');
        [$a,] = $a->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_ATTRIBUTES, $this->connections($a)->detailTab());

        [$missed,] = $a->update(new MouseClickMsg($col, $row - 1, MouseButton::Left, MouseAction::Press));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_ATTRIBUTES, $this->connections($missed)->detailTab(), 'a click off the bar changes nothing');
    }

    public function testAClickOffTheAdminPaneIsIgnored(): void
    {
        Renderer::setSize(160, 50);
        $db = new FakeDatabase();
        $a = new App(ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)));

        [$next, $cmd] = $a->update(new MouseClickMsg(10, 10, MouseButton::Left, MouseAction::Press));
        $this->assertSame($a, $next);
        $this->assertNull($cmd);
    }

    public function testAStatementTimeoutOnAClaimedTabSwitchIgnoresTheKeyInsteadOfCrashing(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Id' => '7', 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'SELECT 1'],
        ]);
        // A focused bar on a page with thread 7 selected, pinned into App
        // state, so a tab switch really reaches the server.
        [$page] = ConnectionsPage::new(new ServerContext($db))->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(ConnectionsPage::class, $page);
        $this->assertTrue($page->detailTabsFocused());
        $this->assertNotSame([Lang::t('conn.none_selected')], $page->detailLines(), 'a thread is selected');
        $a = new App(
            ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)),
            ui: new UiState(pane: Pane::Admin),
            admin: new AdminState(page: $page),
        );

        // The server stalls: the next tab's fetch times out in prepare().
        $db->setPrepareThrows(new StatementTimeoutException());
        foreach ([new KeyMsg(KeyType::Tab), new KeyMsg(KeyType::Tab, shift: true), new KeyMsg(KeyType::Char, '2')] as $key) {
            [$next, $cmd] = $a->update($key);
            $this->assertSame($a, $next, "{$key->string()}: the key is ignored, the TUI survives");
            $this->assertNull($cmd);
        }
    }

    // ── (d) each tab shows its own data for the selected thread ─────────────

    public function testEachDetailTabRendersItsOwnDataForTheSelectedThread(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Id' => '7', 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'SELECT 1'],
        ]);
        $db->setPreparedResult('session_connect_attrs', [
            ['id' => '7', 'attr_name' => '_client_name', 'attr_value' => 'libmysql', 'ordinal' => 0],
        ]);
        $db->setPreparedResult('metadata_locks', [
            ['lock_id' => '1', 'lock_type' => 'SHARED_READ', 'lock_status' => 'GRANTED', 'lock_mode' => 'TRANSACTION', 'thread_id' => '40', 'processlist_id' => '7'],
        ]);
        $db->setPreparedResult('TYPE AS thread_type', [
            // Every column ConnectionDetailTabs::details() selects, so the
            // panel's line cap is exercised at its real width.
            [
                'id' => '7', 'user' => 'app', 'host' => 'h', 'db' => 'shop',
                'command' => 'Query', 'time' => '12', 'state' => 'Sending data',
                'info' => 'SELECT * FROM big_table', 'thread_type' => 'FOREGROUND',
                'thread_name' => 'thread/sql/one_connection',
            ],
        ]);
        $page = ConnectionsPage::new(new ServerContext($db));
        $plain = static fn (ConnectionsPage $p): string => Scan::strip(\preg_replace('/\e\[[0-9;]*m/', '', $p->view()) ?? '');

        $this->assertNull($page->detailLines(), 'the panel starts closed');
        $closed = $plain($page);

        [$details] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertStringContainsString('info: SELECT * FROM big_table', $plain($details), 'the running SQL is never truncated away');
        $this->assertStringContainsString('thread_type: FOREGROUND', $plain($details));
        $this->assertStringContainsString('thread_name: thread/sql/one_connection', $plain($details));
        $this->assertNotContains('…', $details->detailLines() ?? [], 'ten Details columns fit under the cap');

        [$attributes] = $details->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertStringContainsString('_client_name: libmysql', $plain($attributes));
        $this->assertStringNotContainsString('thread_name:', $plain($attributes));

        [$mdl] = $attributes->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertStringContainsString('lock_type=SHARED_READ', $plain($mdl));
        $this->assertStringNotContainsString('_client_name', $plain($mdl));

        [$back] = $mdl->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($back->detailLines(), 'Esc closes the panel');
        $this->assertSame($closed, $plain($back->withDetailTab(ConnectionsPage::DETAIL_TAB_DETAILS)));
    }

    public function testATabTheServerRefusesSaysSoInsteadOfShowingNothing(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Id' => '7', 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'SELECT 1'],
        ]);
        [$page] = ConnectionsPage::new(new ServerContext($db))->update(new KeyMsg(KeyType::Enter));

        $this->assertSame(['No Details data for thread 7'], $page->detailLines());
    }

    // ── (e) leaving the Admin pane drops the bar's key claims ───────────────

    public function testLeavingTheAdminPaneReleasesTheFocusedBar(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($this->connections($a)->detailTabsFocused());

        [$a,] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame(Pane::Tables, $a->ui->pane);
        $this->assertFalse($this->connections($a)->detailTabsFocused(), 'q back to Tables blurs the bar');
        $this->assertNull($this->connections($a)->detailLines());

        for ($i = 0; $i < 3; $i++) {
            [$a,] = $a->update(new KeyMsg(KeyType::Tab));
        }
        $this->assertSame(Pane::Admin, $a->ui->pane);

        [$a,] = $a->update(new KeyMsg(KeyType::Tab));
        $this->assertNotSame(Pane::Admin, $a->ui->pane, 'the first Tab back on Admin cycles the pane');
    }

    // ── (f) the open panel stays inside the terminal height ─────────────────

    /** A server with $threads processlist rows and a full Details row. */
    private function busyDb(int $threads): FakeDatabase
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= $threads; $i++) {
            $rows[] = ['Id' => (string) $i, 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'thread-' . $i . '-sql'];
        }
        $db->setQueryResult($rows);
        $db->setPreparedResult('TYPE AS thread_type', [[
            'id' => '1', 'user' => 'app', 'host' => 'h', 'db' => 'shop',
            'command' => 'Query', 'time' => '12', 'state' => 'Sending data',
            'info' => 'SELECT * FROM big_table', 'thread_type' => 'FOREGROUND',
            'thread_name' => 'thread/sql/one_connection',
        ]]);
        return $db;
    }

    private static function plain(string $view): string
    {
        return Scan::strip(\preg_replace('/\e\[[0-9;]*m/', '', $view) ?? '');
    }

    public function testAnOpenPanelStaysInsideThePagesHeightBudget(): void
    {
        $page = ConnectionsPage::new(new ServerContext($this->busyDb(8)));
        $closed = \count(\explode("\n", $page->view()));

        [$roomy] = $page->withHeightBudget($closed + 20)->update(new KeyMsg(KeyType::Enter));
        $view = self::plain($roomy->view());
        $this->assertStringContainsString('thread_name: thread/sql/one_connection', $view, 'room to spare: the whole panel shows');
        $this->assertStringContainsString('thread-8-sql', $view, 'room to spare: every row shows');

        // Two free rows: the panel still gets four lines (cut with an
        // ellipsis) and the table gives up the other two rows, so the page
        // never grows past its budget and the key hints stay on screen.
        [$tight] = $page->withHeightBudget($closed + 2)->update(new KeyMsg(KeyType::Enter));
        $lines = \explode("\n", self::plain($tight->view()));
        $this->assertCount($closed + 2, $lines, 'the page fills its budget exactly');
        $this->assertStringContainsString(Lang::t('conn.help_tabs'), (string) \end($lines), 'the key hints stay the last line');
        $this->assertContains('   …', $lines, 'the cut panel ends in an ellipsis line');
        $this->assertStringContainsString('thread-6-sql', \implode("\n", $lines));
        $this->assertStringNotContainsString('thread-7-sql', \implode("\n", $lines), 'the table gives up exactly the missing rows');

        $this->assertSame($page->view(), $page->withHeightBudget($closed)->view(), 'a closed panel renders the same with or without a budget');
    }

    public function testOpeningThePanelOnAShortTerminalKeepsTheTableAndHintsOnScreen(): void
    {
        $db = $this->busyDb(12);
        // The page pinned into state, so its processlist is the fixture's
        // rows rather than the async cache's (empty) first poll.
        $a = new App(
            ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)),
            ui: new UiState(Pane::Admin),
            admin: new AdminState(page: ConnectionsPage::new(new ServerContext($db))),
        );

        // The shortest terminal that shows the whole closed screen: the last
        // thread, the page's hints, and the app help line under the query
        // pane (a cut frame drops that line first).
        $appHelp = Lang::t('ui.help.admin', ['count' => \count(AdminPane::orderedCases())]);
        $rows = null;
        for ($h = 20; $h <= 120; $h++) {
            Renderer::setSize(200, $h);
            $plain = self::plain($a->view());
            if (\str_contains($plain, 'thread-12-sql') && \str_contains($plain, Lang::t('conn.help_full'))
                && \str_contains($plain, $appHelp)) {
                $rows = $h;
                break;
            }
        }
        $this->assertNotNull($rows, 'some terminal height fits the closed page');

        [$a,] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($this->connections($a)->detailLines());
        $plain = self::plain($a->view());
        $this->assertStringContainsString(Lang::t('conn.help_tabs'), $plain, 'Enter must not push the key hints out of the frame');
        $this->assertStringContainsString($appHelp, $plain, 'nor the app help line under the query pane');
        $this->assertStringContainsString('user: app', $plain, 'the panel shows, cut to fit');
        $this->assertStringContainsString('   …', $plain);

        // With room for the whole panel nothing is cut at all.
        Renderer::setSize(200, $rows + 12);
        $plain = self::plain($a->view());
        $this->assertStringContainsString('thread-12-sql', $plain);
        $this->assertStringContainsString('thread_name: thread/sql/one_connection', $plain);
        $this->assertStringContainsString(Lang::t('conn.help_tabs'), $plain);
    }

    // ── (g) mouse reporting is on only for the Connections page ─────────────

    public function testMouseReportingIsScopedToTheConnectionsPage(): void
    {
        Renderer::setSize(160, 50);
        $db = new FakeDatabase();
        $a = new App(ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)));
        $scoped = new MouseScopedApp($a);

        $frame = $scoped->view();
        $this->assertInstanceOf(View::class, $frame);
        $this->assertSame(MouseMode::Off, $frame->mouseMode, 'the Tables pane keeps native drag-selection');
        $this->assertSame($a->view(), $frame->body);

        for ($i = 0; $i < 3; $i++) {
            [$scoped,] = $scoped->update(new KeyMsg(KeyType::Tab));
            $this->assertInstanceOf(MouseScopedApp::class, $scoped);
            if ($scoped->app->ui->pane !== Pane::Admin) {
                $this->assertSame(MouseMode::Off, $scoped->view()->mouseMode, "pane {$scoped->app->ui->pane->value}: no mouse reporting");
            }
        }
        $this->assertSame(Pane::Admin, $scoped->app->ui->pane);
        $this->assertSame(AdminPane::ProcessList, $scoped->app->admin->pane);
        $this->assertSame(MouseMode::CellMotion, $scoped->view()->mouseMode, 'the Connections page takes clicks');

        [$help,] = $scoped->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertTrue($help->app->ui->helpOverlay);
        $this->assertSame(MouseMode::Off, $help->view()->mouseMode, 'the help overlay covers the bar');

        $other = AdminPane::orderedCases()[1];
        $digit = (string) (\array_search($other, AdminPane::orderedCases(), true) + 1);
        [$picked,] = $scoped->update(new KeyMsg(KeyType::Char, $digit));
        $this->assertSame($other, $picked->app->admin->pane);
        $this->assertSame(MouseMode::Off, $picked->view()->mouseMode, 'another admin page keeps native drag-selection');
    }

    // ── (h) the panel, the highlight and the bar agree on one thread ───────

    /** @param list<string> $ids */
    private static function threads(array $ids): array
    {
        return \array_map(
            static fn (string $id): array => ['Id' => $id, 'User' => 'app', 'Host' => 'h', 'db' => '', 'Command' => 'Query', 'Time' => '0', 'State' => '', 'Info' => 'thread-' . $id . '-sql'],
            $ids,
        );
    }

    public function testAReloadRefetchesTheOpenPanelForTheThreadNowUnderTheSelection(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult(self::threads(['7', '8']));
        $page = ConnectionsPage::new(new ServerContext($db));
        [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        [$page] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['No Details data for thread 8'], $page->detailLines());

        // A row lands above the selection: index 1 now names thread 7.
        $db->setQueryResult(self::threads(['5', '7', '8']));
        [$reloaded, $cmd] = $page->update(new ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertSame(['No Details data for thread 7'], $reloaded->detailLines(), 'the panel follows the reload');

        // r swaps the provider too, so it refetches the open panel as well.
        $db->setQueryResult(self::threads(['9', '8']));
        [$refreshed] = $reloaded->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(['No Details data for thread 8'], $refreshed->detailLines());

        // A closed panel stays closed across a reload.
        [$closed] = $refreshed->update(new KeyMsg(KeyType::Escape));
        [$closed] = $closed->update(new ReloadReportMsg());
        $this->assertNull($closed->detailLines());
    }

    public function testTheTableHighlightsTheRowTheDetailPanelDescribes(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult(self::threads(['7', '8', '9']));
        $page = ConnectionsPage::new(new ServerContext($db));
        $highlighted = static fn (ConnectionsPage $p): array => self::highlightedIn($p->table()->View());

        $this->assertSame(['7'], $highlighted($page));
        [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        [$page] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['No Details data for thread 9'], $page->detailLines());
        $this->assertSame(['9'], $highlighted($page), 'the highlight sits on the thread the panel shows');

        // Rows removed under the selection: no row is highlighted, not a wrong one.
        $db->setQueryResult(self::threads(['7']));
        [$page] = $page->update(new ReloadReportMsg());
        $this->assertSame([], $highlighted($page));
    }

    /**
     * The thread ids on highlighted (reverse-video) lines of a rendered frame.
     *
     * @return list<string>
     */
    private static function highlightedIn(string $view): array
    {
        $hits = [];
        foreach (\explode("\n", $view) as $line) {
            // Reverse video (SGR 7), alone or merged into a longer SGR.
            if (\preg_match('/\e\[(?:[0-9]+;)*7(?:;[0-9]+)*m/', $line) === 1
                && \preg_match('/thread-(\d+)-sql/', self::plain($line), $m) === 1) {
                $hits[] = $m[1];
            }
        }
        return \array_values(\array_unique($hits));
    }

    public function testASelectionInTheRowsThePanelTrimsStaysPaintedAndHighlighted(): void
    {
        $page = ConnectionsPage::new(new ServerContext($this->busyDb(8)));
        $closed = \count(\explode("\n", $page->view()));

        [$page] = $page->withHeightBudget($closed + 2)->update(new KeyMsg(KeyType::Enter));
        for ($i = 0; $i < 7; $i++) {
            [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame(7, $page->selectedIndex());

        $view = $page->view();
        $this->assertCount($closed + 2, \explode("\n", $view), 'the page still fills its budget exactly');
        $this->assertSame(['8'], self::highlightedIn($view), 'the panel thread is painted and highlighted');
        $this->assertStringNotContainsString('thread-1-sql', self::plain($view), 'the window scrolled, not trimmed below the cursor');
        $this->assertStringNotContainsString('thread-2-sql', self::plain($view));
    }

    public function testASelectionPastTheRowWindowScrollsTheTableToIt(): void
    {
        $page = ConnectionsPage::new(new ServerContext($this->busyDb(60)));
        [$page] = $page->update(new KeyMsg(KeyType::Enter));
        for ($i = 0; $i < 55; $i++) {
            [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame(55, $page->selectedIndex());

        $view = $page->view();
        $this->assertSame(['56'], self::highlightedIn($view));
        $plain = self::plain($view);
        $this->assertStringContainsString('thread-7-sql', $plain, 'fifty rows end at the selection');
        $this->assertStringNotContainsString('thread-6-sql', $plain);
        $this->assertStringNotContainsString('thread-57-sql', $plain);
    }

    public function testAReloadReadsThePanelOnlyForANewThreadAndSurvivesAFailedRead(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult(self::threads(['7', '8']));
        $page = ConnectionsPage::new(new ServerContext($db));
        [$page] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        [$page] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['No Details data for thread 8'], $page->detailLines());

        // Any read from here on fails with a non-PDO error.
        $db->setPrepareThrows(new \RuntimeException('server gone'));

        // A steady poll: the same thread stays under the selection, so no read.
        [$steady] = $page->update(new ReloadReportMsg());
        $this->assertSame(['No Details data for thread 8'], $steady->detailLines(), 'no read on an unchanged selection');
        $this->assertTrue($steady->detailTabsFocused());

        // A different thread lands under the selection: the read fails, the
        // panel closes, and the processlist refresh is kept.
        $db->setQueryResult(self::threads(['5', '7', '8']));
        [$moved, $cmd] = $steady->update(new ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertNull($moved->detailLines(), 'a failed read closes the panel instead of describing the wrong thread');
        $this->assertFalse($moved->detailTabsFocused());
        $this->assertCount(3, $moved->filteredProcesslist(), 'the processlist refresh survives the failed read');

        $db->setPrepareThrows(null);
        [$reopened] = $moved->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['No Details data for thread 7'], $reopened->detailLines());
    }

    public function testWithDetailTabRefetchesAnOpenPanelAndLeavesAClosedOneClosed(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult(self::threads(['7']));
        $page = ConnectionsPage::new(new ServerContext($db));

        $closed = $page->withDetailTab(ConnectionsPage::DETAIL_TAB_MDL);
        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $closed->detailTab());
        $this->assertNull($closed->detailLines());

        [$open] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['No Details data for thread 7'], $open->detailLines());
        $mdl = $open->withDetailTab(ConnectionsPage::DETAIL_TAB_MDL);
        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $mdl->detailTab());
        $this->assertSame(['No MDL data for thread 7'], $mdl->detailLines(), 'the panel follows the bar');
    }
}
