<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Query\App;
use SugarCraft\Query\App\BrowseState;
use SugarCraft\Query\App\ConnectionState;
use SugarCraft\Query\Core\Msg\TableRowsLoadedMsg;
use SugarCraft\Query\Database;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;
use SugarCraft\Query\Renderer;
use SugarCraft\Query\Tests\Admin\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function db(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $db->exec("INSERT INTO users (name) VALUES ('alice'), ('bob'), ('carol')");
        $db->exec("INSERT INTO posts (title) VALUES ('hello'), ('world')");
        return $db;
    }

    public function testStartListsTablesAndLoadsFirst(): void
    {
        $a = App::start($this->db());
        $this->assertSame(['posts', 'users'], $a->browse->tables);
        $this->assertSame('posts', $a->browse->selectedTable);
        $this->assertCount(2, $a->browse->rows);
    }

    public function testTabCyclesPanes(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Rows, $a->ui->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Query, $a->ui->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        // Tab cycles through Admin as well: Tables → Rows → Query → Admin → Tables
        $this->assertSame(Pane::Admin, $a->ui->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Tables, $a->ui->pane);
    }

    /**
     * Digit keys select panes by sidebar display order (Management section
     * first, then Performance section). All 8 panes are reachable by digit.
     * Previously the handler used enum case order which differed from the
     * section-grouped sidebar order, so pressing digit N did NOT select the
     * pane shown at row N in the sidebar.
     */
    public function testNumberKeySelectsLaterAdminPanes(): void
    {
        $a = App::start($this->db());
        // Tables → Rows → Query → Admin
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Admin, $a->ui->pane);

        // Sidebar display order (Management, then Performance):
        // 1=ProcessList, 2=Variables, 3=Status, 4=Debug,
        // 5=QueryStats, 6=Dashboard, 7=TableStats, 8=PerfSchema
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '7'));
        $this->assertSame(\SugarCraft\Query\Admin\AdminPane::TableStats, $a->admin->pane);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '8'));
        $this->assertSame(\SugarCraft\Query\Admin\AdminPane::PerfSchema, $a->admin->pane);
    }

    /**
     * Regression: subscriptions() drives the admin polling tick that drains
     * page-driven queries. It was dead code (never wired into ProgramOptions),
     * which hid a missing import and a static call to the instance method
     * Subscriptions::withTick(). Guard the whole path: no tick outside admin,
     * tick registered immediately on admin entry (throttle gates the fetch, not
     * the tick), and a produce() closure that yields a Msg (not a fatal).
     */
    public function testAdminSubscriptionTickIsWiredAndProducesAMsg(): void
    {
        $a = App::start($this->db());
        $this->assertNull($a->subscriptions(), 'no subscriptions outside the admin pane');

        // Tables → Rows → Query → Admin (entering admin sets adminLoading=true).
        // Throttle gates the actual fetch; the tick is registered immediately.
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $subs = $a->subscriptions();
        $this->assertNotNull($subs, 'tick registered immediately (throttle gates fetch, not tick)');
        $this->assertTrue($subs->has('admin-fetch'));

        // The tick's produce() must yield a Msg the Program can dispatch.
        $produced = ($subs->all()[0]->produce)();
        $this->assertInstanceOf(\SugarCraft\Core\Msg::class, $produced);
    }

    /**
     * Regression: unhandled admin keys (Tab, Space, 'a', etc.) must reach the
     * active page's update() so pages like VariablesPage can respond to nav
     * keys, and the returned page must be stored back in App's adminPage.
     * App-level keys (digits, q, j/k, p, r) take precedence and do NOT
     * reach the page's update() — this is the deliberate precedence design.
     */
    public function testUnhandledAdminKeyDelegatesToPageUpdate(): void
    {
        $a = App::start($this->db());

        // Navigate: Tables → Rows → Query → Admin
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(\SugarCraft\Query\Pane::Admin, $a->ui->pane);

        // Switch to DashboardPage (pane 6 — sidebar display order is
        // Management: ProcessList,Variables,Status,Debug then Performance:
        // QueryStats,Dashboard,TableStats,PerfSchema).
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '6'));
        $this->assertSame(\SugarCraft\Query\Admin\AdminPane::Dashboard, $a->admin->pane);

        // 'a' is NOT an app-level key, so it reaches DashboardPage->update().
        // DashboardPage responds to 'a' by clearing alerts and returning a new page.
        $pageBefore = $a->adminPage();
        $this->assertSame(0, $pageBefore->alertCount(), 'sanity: no alerts initially');

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));

        $pageAfter = $a->adminPage();
        // The page should have been updated and stored back in App.
        $this->assertNotSame($pageBefore, $pageAfter, 'DashboardPage should be a new instance after handling "a"');
        $this->assertSame(0, $pageAfter->alertCount());
    }

    /**
     * Regression: adminPage state must survive a poll-tick refresh cycle.
     * Previously withAdminLoading(true) nulled adminPage, destroying in-memory
     * state (tab, cursor, pending edits) on every tick. Now adminPage is
     * preserved across refresh cycles and only nulled when the pane changes.
     */
    public function testAdminPageStateSurvivesRefreshCycle(): void
    {
        $a = App::start($this->db());

        // Enter Admin and wait for initial data (so adminPage is created).
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(\SugarCraft\Query\Pane::Admin, $a->ui->pane);
        // Trigger initial fetch and data arrival.
        [$a, ] = $a->update(new \SugarCraft\Query\Core\Msg\AdminFetchStartedMsg());
        [$a, ] = $a->update(new \SugarCraft\Query\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '12345'],
            ['max_connections' => '100'],
            microtime(true),
        ));
        // adminPage is now lazily created.
        $createdPage = $a->adminPage();

        // Switch to DashboardPage (pane 6 in sidebar display order) and
        // toggle pause via the app-level 'p' handler.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '6'));
        $this->assertSame(\SugarCraft\Query\Admin\AdminPane::Dashboard, $a->admin->pane);
        $pageBefore = $a->adminPage();
        $this->assertFalse($pageBefore->isPaused());
        // Press 'p' — app-level handler toggles pause and stores updated page.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'p'));
        $pageAfterPause = $a->adminPage();
        $this->assertTrue($pageAfterPause->isPaused(), 'pause should be toggled');
        $this->assertNotSame($pageBefore, $pageAfterPause);

        // Simulate poll tick: AdminFetchStartedMsg (loading starts).
        // With the fix, adminPage is NOT nulled here.
        [$a, ] = $a->update(new \SugarCraft\Query\Core\Msg\AdminFetchStartedMsg());

        // adminPage must be the SAME instance — not recreated.
        $this->assertSame(
            $pageAfterPause,
            $a->adminPage(),
            'adminPage should survive AdminFetchStartedMsg (no state loss)',
        );
        $this->assertTrue($a->adminPage()->isPaused(), 'pause state must be preserved');

        // Simulate data arriving: AdminDataLoadedMsg (loading clears).
        // adminPage is still NOT nulled here.
        [$a, ] = $a->update(new \SugarCraft\Query\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '67890'],
            ['max_connections' => '200'],
            microtime(true),
        ));

        // adminPage must STILL be the same instance with state preserved.
        $finalPage = $a->adminPage();
        $this->assertSame(
            $pageAfterPause,
            $finalPage,
            'adminPage should survive AdminDataLoadedMsg (no state loss)',
        );
        $this->assertTrue($finalPage->isPaused(), 'pause state must be preserved after data refresh');
    }

    public function testJKMovesTableCursor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $a->browse->tableCursor);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(0, $a->browse->tableCursor);
    }

    public function testUpAtTopWrapsToBottom(): void
    {
        // db() has two tables; App::start lands on index 0.
        $a = App::start($this->db());
        $this->assertSame(0, $a->browse->tableCursor);

        [$a, ] = $a->update(new KeyMsg(KeyType::Up, ''));

        $this->assertSame(1, $a->browse->tableCursor, 'up at the top wraps to the last table');
        $this->assertSame('users', $a->browse->selectedTable);
    }

    public function testDownAtBottomWrapsToTop(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, '')); // to the last table
        $this->assertSame(1, $a->browse->tableCursor);

        [$a, ] = $a->update(new KeyMsg(KeyType::Down, '')); // wraps back to the first

        $this->assertSame(0, $a->browse->tableCursor, 'down at the bottom wraps to the first table');
        $this->assertSame('posts', $a->browse->selectedTable);
    }

    public function testEnterLoadsSelectedTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('users', $a->browse->selectedTable);
        $this->assertCount(3, $a->browse->rows);
    }

    /**
     * Moving the cursor through the tables list loads the highlighted table's
     * rows immediately, giving the user a live preview as they navigate.
     * Enter/Space still works as before for explicit selection.
     */
    public function testNavigationLoadsRows(): void
    {
        $a = App::start($this->db());
        // App::start loads the first table ('posts', 2 rows).
        $this->assertSame('posts', $a->browse->selectedTable);
        $this->assertCount(2, $a->browse->rows);

        // Move the cursor down to 'users' — rows should update to 'users' table.
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $a->browse->tableCursor);
        $this->assertSame('users', $a->browse->selectedTable, 'cursor move must load the highlighted table');
        $this->assertCount(3, $a->browse->rows, 'cursor move must load rows for highlighted table');

        // Move back up to 'posts' — rows should update back.
        [$a, ] = $a->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(0, $a->browse->tableCursor);
        $this->assertSame('posts', $a->browse->selectedTable);
        $this->assertCount(2, $a->browse->rows);

        // Enter also loads (confirming it still works).
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('posts', $a->browse->selectedTable);
    }

    /**
     * Regression: the framework's WindowSizeMsg is the single source of truth
     * for terminal dimensions. App must forward it to the Renderer (and leave
     * the model otherwise unchanged) so layout matches the real screen.
     */
    public function testWindowSizeMsgUpdatesRendererSize(): void
    {
        $a = App::start($this->db());
        try {
            [$a2, $cmd] = $a->update(new WindowSizeMsg(123, 45));
            $this->assertNull($cmd);
            $this->assertSame($a, $a2, 'WindowSizeMsg should not mutate the model');
            $this->assertSame(['rows' => 45, 'cols' => 123], Renderer::getTerminalSize());
        } finally {
            Renderer::resetSizeCache();
        }
    }

    public function testQuitFromTablesPane(): void
    {
        $a = App::start($this->db());
        [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testQuitDoesNotFireFromQueryPane(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → rows
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        [$a2, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNull($cmd);
        // 'q' should land in the editor.
        $this->assertSame('q', $a2->editor()->value());
    }

    public function testCharsAccumulateInQueryBuffer(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (['s', 'e', 'l', 'e', 'c', 't'] as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        $this->assertSame('select', $a->editor()->value());
    }

    public function testCtrlREnterRunsQueryAndPopulatesRows(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Manually splat a SELECT into the buffer.
        $sql = 'SELECT name FROM users ORDER BY name';
        foreach (str_split($sql) as $c) {
            $msg = $c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c);
            [$a, ] = $a->update($msg);
        }
        // Ctrl+r runs.
        $msg = new KeyMsg(KeyType::Char, 'r', ctrl: true);
        [$a, ] = $a->update($msg);
        $this->assertNull($a->ui->error);
        $this->assertCount(3, $a->browse->rows);
        $this->assertSame('alice', $a->browse->rows[0]['name']);
    }

    public function testInvalidQueryStashesErrorWithoutThrowing(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('NOPE') as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->ui->error);
    }

    public function testBackspaceDropsLastChar(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        foreach (['a','b','c'] as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('ab', $a->editor()->value());
    }

    public function testRunQueryRecordsHistoryAndClearsEditor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type and run first query
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(['SELECT 1'], $a->query->history);
        // Editor is reset after a successful run.
        $this->assertSame('', $a->editor()->value());
        // Type and run second query — newest lands at the front.
        foreach (str_split('SELECT 2') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(['SELECT 2', 'SELECT 1'], $a->query->history);
    }

    public function testRunQueryPopulatesResultTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('SELECT name FROM users') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNull($a->ui->error);
        $this->assertNotNull($a->browse->resultTable);
        $this->assertStringContainsString('alice', $a->browse->resultTable->render());
    }

    public function testLoadTableClearsResultTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → rows
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->browse->resultTable);
        // Cycle Query → Admin → Tables, then Enter loads a table, which drops
        // the query result viewer back to the sugar-table browse grid.
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → admin
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → tables
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, '')); // load selected table
        $this->assertNull($a->browse->resultTable);
    }

    public function testEnterInsertsNewlineInEditor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame("a\nb", $a->editor()->value());
        $this->assertSame(2, $a->editor()->lineCount());
    }

    public function testCtrlFFavoritesQuery(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type a query
        foreach (str_split('SELECT 42') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        // Ctrl+F to favorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        $this->assertContains('SELECT 42', $a->query->favorites);
    }

    public function testCtrlShiftFUnfavoritesQuery(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type a query
        foreach (str_split('SELECT 42') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        // Ctrl+F to favorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        $this->assertContains('SELECT 42', $a->query->favorites);
        // Ctrl+Shift+F to unfavorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true, shift: true));
        $this->assertNotContains('SELECT 42', $a->query->favorites);
    }

    public function testHistoryNotDuplicatedOnMultipleRuns(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type and run same query twice
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        // Modify and run again (add nothing - just run same)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        // Should only have one entry
        $this->assertCount(1, $a->query->history);
        $this->assertSame('SELECT 1', $a->query->history[0]);
    }

    /**
     * On MySQL/Postgres, cursoring onto a table must NOT block on a synchronous
     * fetch (the asset_media freeze). Instead the model is marked loading, the
     * title follows the cursor immediately, and an async Cmd is returned to run
     * the blob-safe fetch on the React loop. The rows arrive later via
     * {@see TableRowsLoadedMsg}.
     */
    public function testMysqlCursorDownLoadsRowsAsynchronously(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['posts', 'users'])->withSelectedTable('posts'),
        );
        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Down, ''));

        $this->assertSame(1, $next->browse->tableCursor);
        $this->assertSame('users', $next->browse->selectedTable, 'title follows the cursor immediately');
        $this->assertTrue($next->browse->rowsLoading, 'rows marked loading while the fetch is in flight');
        $this->assertSame([], $next->browse->rows, 'no stale rows shown during load');
        $this->assertNotNull($cmd, 'MySQL browse returns an async Cmd, not a blocking fetch');
    }

    public function testTableRowsLoadedMsgPopulatesRowsAndClearsLoading(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['posts', 'users'])->withSelectedTable('users')->withRowsLoading(true),
        );
        [$next, $cmd] = $app->update(new TableRowsLoadedMsg('users', [['id' => 1], ['id' => 2]]));

        $this->assertNull($cmd);
        $this->assertFalse($next->browse->rowsLoading);
        $this->assertCount(2, $next->browse->rows);
        $this->assertSame('2 rows', $next->ui->status);
    }

    public function testStaleTableRowsResultForOtherTableIsIgnored(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['posts', 'users'])->withSelectedTable('users')->withRowsLoading(true),
        );
        // A result for 'posts' arrives after the user already moved on to 'users'.
        [$next, ] = $app->update(new TableRowsLoadedMsg('posts', [['id' => 99]]));

        $this->assertSame([], $next->browse->rows, 'stale result for a different table is dropped');
        $this->assertTrue($next->browse->rowsLoading, 'the in-flight load keeps its spinner');
    }

    public function testTableRowsLoadedMsgErrorStashesError(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['users'])->withSelectedTable('users')->withRowsLoading(true),
        );
        [$next, ] = $app->update(new TableRowsLoadedMsg('users', [], 'Table dropped'));

        $this->assertFalse($next->browse->rowsLoading);
        $this->assertSame('Table dropped', $next->ui->error);
    }

    public function testRowsPaneShowsLoadingIndicatorWhileFetching(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['users'])->withSelectedTable('users')->withRowsLoading(true),
        );
        $this->assertStringContainsString('loading', $app->view());
    }

}
