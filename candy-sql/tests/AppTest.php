<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Core\Msg\TableRowsLoadedMsg;
use SugarCraft\Sql\Database;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;
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
        $this->assertSame(\SugarCraft\Sql\Admin\AdminPane::TableStats, $a->admin->pane);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '8'));
        $this->assertSame(\SugarCraft\Sql\Admin\AdminPane::PerfSchema, $a->admin->pane);
    }

    /**
     * Regression: subscriptions() drives the admin polling tick that drains
     * page-driven queries. It was dead code (never wired into ProgramOptions),
     * which hid a missing import and a static call to the instance method
     * Subscriptions::withTick(). Guard the whole path: no tick outside admin,
     * tick registered immediately on admin entry (throttle gates the fetch, not
     * the tick), and a produce() closure that yields a Msg (not a fatal).
     *
     * The tick must be a STABLE declaration emitting AdminTickMsg: the throttle
     * decision (full fetch / pending drain / silence) moved per-fire into
     * update()'s AdminTickMsg arm because Program::reconcileSubscriptions()
     * diffs only by id — a declaration-time branch would be locked in at
     * install time forever (and its cooldown variant once called the
     * non-existent Cmd::none(), fataling every tick).
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

        // The tick's produce() must yield the per-fire message that update()'s
        // AdminTickMsg arm consumes — never a Cmd (Cmd::none never existed).
        $produced = ($subs->all()[0]->produce)();
        $this->assertInstanceOf(\SugarCraft\Sql\Core\Msg\AdminTickMsg::class, $produced);

        // Stability leg: after a load lands and a new fetch starts (the throttle
        // states the old code branched on HERE), the declaration stays the same
        // AdminTickMsg tick. Program locks the first installed closure per id,
        // so any branching here would freeze a wrong state in for the pane's
        // entire lifetime.
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '1'],
            ['max_connections' => '100'],
            microtime(true),
        ));
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg());
        $subs = $a->subscriptions();
        $this->assertNotNull($subs);
        $this->assertTrue($subs->has('admin-fetch'));
        $this->assertInstanceOf(
            \SugarCraft\Sql\Core\Msg\AdminTickMsg::class,
            ($subs->all()[0]->produce)(),
            'the tick declaration never branches at declaration time',
        );
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
        $this->assertSame(\SugarCraft\Sql\Pane::Admin, $a->ui->pane);

        // Switch to DashboardPage (pane 6 — sidebar display order is
        // Management: ProcessList,Variables,Status,Debug then Performance:
        // QueryStats,Dashboard,TableStats,PerfSchema).
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '6'));
        $this->assertSame(\SugarCraft\Sql\Admin\AdminPane::Dashboard, $a->admin->pane);

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
        $this->assertSame(\SugarCraft\Sql\Pane::Admin, $a->ui->pane);
        // Trigger initial fetch and data arrival.
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg());
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '12345'],
            ['max_connections' => '100'],
            microtime(true),
        ));
        // adminPage is now lazily created.
        $createdPage = $a->adminPage();

        // Switch to DashboardPage (pane 6 in sidebar display order) and
        // toggle pause via the app-level 'p' handler.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '6'));
        $this->assertSame(\SugarCraft\Sql\Admin\AdminPane::Dashboard, $a->admin->pane);
        $pageBefore = $a->adminPage();
        $this->assertFalse($pageBefore->isPaused());
        // Press 'p' — app-level handler toggles pause and stores updated page.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'p'));
        $pageAfterPause = $a->adminPage();
        $this->assertTrue($pageAfterPause->isPaused(), 'pause should be toggled');
        $this->assertNotSame($pageBefore, $pageAfterPause);

        // Simulate poll tick: AdminFetchStartedMsg (loading starts).
        // With the fix, adminPage is NOT nulled here.
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg());

        // adminPage must be the SAME instance — not recreated.
        $this->assertSame(
            $pageAfterPause,
            $a->adminPage(),
            'adminPage should survive AdminFetchStartedMsg (no state loss)',
        );
        $this->assertTrue($a->adminPage()->isPaused(), 'pause state must be preserved');

        // Simulate data arriving: AdminDataLoadedMsg (loading clears).
        // adminPage is still NOT nulled here.
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg(
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
        // SQL-O10: wrapping moved the cursor only — the start-loaded preview
        // stands until Enter explicitly loads the highlighted table.
        $this->assertSame('posts', $a->browse->selectedTable, 'cursor move never fetches');
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('users', $a->browse->selectedTable, 'Enter loads the wrapped-to table');
    }

    public function testDownAtBottomWrapsToTop(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, '')); // to the last table
        $this->assertSame(1, $a->browse->tableCursor);

        [$a, ] = $a->update(new KeyMsg(KeyType::Down, '')); // wraps back to the first

        $this->assertSame(0, $a->browse->tableCursor, 'down at the bottom wraps to the first table');
        // SQL-O10: the move is local; Enter is the only load trigger.
        $this->assertSame('posts', $a->browse->selectedTable);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('posts', $a->browse->selectedTable);
        $this->assertCount(2, $a->browse->rows, 'Enter loaded the wrapped-to table');
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
     * SQL-O10: `/` opens a live filter line over the table names; the cursor
     * then indexes the narrowed view, and Enter (the pane's only load key)
     * fetches the filtered selection.
     */
    private function filterApp(): App
    {
        return new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['audit', 'orders', 'users']),
        );
    }

    public function testSlashOpensTheFilterLine(): void
    {
        [$a, $cmd] = $this->filterApp()->update(new KeyMsg(KeyType::Char, '/'));

        $this->assertNull($cmd, 'opening the line is local');
        $this->assertTrue($a->browse->filterMode);
        $this->assertSame('', $a->browse->filterText);
        $this->assertCount(3, $a->browse->visibleTables(), 'an open line with no text still shows everything');
    }

    public function testFilterNarrowsTheListAndEnterLoadsTheFilteredRow(): void
    {
        $a = $this->filterApp();
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['o', 'r'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertSame('or', $a->browse->filterText);
        $this->assertSame(['orders'], $a->browse->visibleTables());

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($a->browse->filterMode, 'Enter commits the line');
        $this->assertSame('orders', $a->browse->selectedTable, 'Enter loads the table under the filtered cursor');
        $this->assertNotNull($cmd, 'the load still rides the MySQL async Cmd');
    }

    public function testFilterIsCaseInsensitiveAndTypingPinsCursorToTop(): void
    {
        $a = $this->filterApp();
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'O'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(['orders'], $a->browse->visibleTables(), 'OR');

        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(0, $a->browse->tableCursor, 'arrows stay in range of the narrowed list');

        // Widen again with a second cursor row, then narrow under it: the
        // cursor must re-pin to the top, never point past the end.
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u')); // audit + users? 'u' rides both
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        $this->assertGreaterThanOrEqual(1, $a->browse->tableCursor);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's')); // 'us' → users only
        $this->assertSame(['users'], $a->browse->visibleTables());
        $this->assertSame(0, $a->browse->tableCursor, 'typing re-pins the cursor to the top of the narrowed view');
    }

    public function testBackspaceEditsAndEscapeAbandonsWithoutQuitting(): void
    {
        $a = $this->filterApp();
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'z'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame([], $a->browse->visibleTables(), 'nothing carries "zi"');

        [$a, ] = $a->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('z', $a->browse->filterText);
        $this->assertSame([], $a->browse->visibleTables(), 'still nothing under "z"');

        [$a, ] = $a->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('', $a->browse->filterText, 'backspacing the last rune leaves the line open and unfiltered');
        $this->assertCount(3, $a->browse->visibleTables());

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cmd, 'Escape while the line is open abandons the line, it never quits');
        $this->assertFalse($a->browse->filterMode);
        $this->assertSame('', $a->browse->filterText, 'abandoning clears the filter too');
    }

    public function testQIsAFilterLetterWhileTheLineIsOpen(): void
    {
        [$a, ] = $this->filterApp()->update(new KeyMsg(KeyType::Char, '/'));
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));

        $this->assertNull($cmd, 'q inside the filter line is text, not the quit key');
        $this->assertSame('q', $a->browse->filterText);

        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd, 'once the line closes, q quits again');
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testFilterRendersNoMatchAndHonestBadge(): void
    {
        $a = $this->filterApp();
        foreach (['/', 'z', 'z'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }

        $view = $a->view();

        $this->assertStringContainsString('(no match)', $view, 'a filter excluding everything says so');
        $this->assertStringContainsString('/zz', $view, 'the open line is echoed in the pane title');
        $this->assertStringContainsString('Tables: 0/3', $view, 'the badge reads visible/total while filtering');

        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertStringContainsString('Tables: 3', $a->view(), 'and returns to the plain total after abandon');
    }

    /** A 250-row table beside the two small ones — the paging fixture. */
    private function bulkDb(): Database
    {
        $a = $this->db();
        $db = $a;
        $db->exec('CREATE TABLE bulk (n INTEGER PRIMARY KEY)');
        foreach (range(1, 250) as $n) {
            $db->exec('INSERT INTO bulk (n) VALUES (' . $n . ')');
        }
        return $db;
    }

    public function testPgDnWalksThePreviewInServerPages(): void
    {
        $a = App::start($this->bulkDb());
        $this->assertSame('bulk', $a->browse->selectedTable);
        $this->assertCount(100, $a->browse->rows, 'page 1 holds exactly the 100-row window');
        $this->assertSame('page 1 (rows 1–100)', $a->ui->status);

        [$a, ] = $a->update(new KeyMsg(KeyType::PageDown, ''));
        $this->assertSame(100, $a->browse->pageOffset);
        $this->assertCount(100, $a->browse->rows);
        $this->assertSame(101, (int) $a->browse->rows[0]['n'], 'row 101 is the first of page 2');
        $this->assertSame('page 2 (rows 101–200)', $a->ui->status);

        [$a, ] = $a->update(new KeyMsg(KeyType::PageDown, ''));
        $this->assertSame(200, $a->browse->pageOffset);
        $this->assertCount(50, $a->browse->rows, 'the tail page carries the remaining 50');
        $this->assertSame(201, (int) $a->browse->rows[0]['n']);
        $this->assertSame('page 3 (rows 201–250)', $a->ui->status);

        // A short page IS the end — forward presses answer honestly, no fetch.
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::PageDown, ''));
        $this->assertNull($cmd, 'no phantom probe past the tail page');
        $this->assertSame(200, $a->browse->pageOffset, 'the end guard does not move the window');
        $this->assertSame('end of table — no further page', $a->ui->status);

        [$a, ] = $a->update(new KeyMsg(KeyType::PageUp, ''));
        $this->assertSame(100, $a->browse->pageOffset);
        $this->assertSame(101, (int) $a->browse->rows[0]['n'], 'back to page 2');
        $this->assertSame('page 2 (rows 101–200)', $a->ui->status);

        [$a, ] = $a->update(new KeyMsg(KeyType::PageUp, ''));
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::PageUp, ''));
        $this->assertSame(0, $a->browse->pageOffset);
        $this->assertNull($cmd, 'back from page 1 is a no-op');
        $this->assertSame('already at the first page', $a->ui->status);
    }

    public function testPagingKeysStayInertOnQueryResults(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()
                ->withTables(['posts', 'users'])
                ->withSelectedTable('(query)')
                ->withRows(array_fill(0, 100, ['n' => 1])),
        );
        [$next, $cmd] = $app->update(new KeyMsg(KeyType::PageDown, ''));

        $this->assertNull($cmd, 'a buffered query result has no next server page to fetch');
        $this->assertSame('(query)', $next->browse->selectedTable);
        $this->assertSame(0, $next->browse->pageOffset);
    }

    public function testEmptyAsyncPageRewindsToTheLastFullOne(): void
    {
        // The exactly-divisible-table race: PgDn dispatched page 2, the table
        // ends at row 200, the answer is empty. The fold must not park the
        // user on a blank pane — it rewinds and re-fetches page 1.
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()
                ->withTables(['posts', 'users'])
                ->withSelectedTable('users')
                ->withRowsLoading(true)
                ->withPageOffset(100),
        );
        [$next, $cmd] = $app->update(new TableRowsLoadedMsg('users', [], null, 0));

        $this->assertSame(0, $next->browse->pageOffset, 'the walk back landed on page 1');
        $this->assertTrue($next->browse->rowsLoading, 'the rewind is itself a load');
        $this->assertNotNull($cmd, 'the re-fetch rides the async Cmd');
    }

    /**
     * SQL-O10: cursor movement through the tables list is a pure local edit —
     * the highlighted table's rows load ONLY on Enter/Space. The old arm
     * fetched a preview per hovered row (one round trip per arrow press).
     */
    public function testNavigationMovesLocallyAndEnterLoads(): void
    {
        $a = App::start($this->db());
        // App::start loads the first table ('posts', 2 rows).
        $this->assertSame('posts', $a->browse->selectedTable);
        $this->assertCount(2, $a->browse->rows);

        // Move the cursor down to 'users' — the cursor moves, nothing fetches.
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $a->browse->tableCursor);
        $this->assertSame('posts', $a->browse->selectedTable, 'cursor move must not change the selection');
        $this->assertCount(2, $a->browse->rows, 'cursor move must not fetch rows');

        // Up past the top wraps — still zero fetches.
        [$a, ] = $a->update(new KeyMsg(KeyType::Up, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1, $a->browse->tableCursor);
        $this->assertCount(2, $a->browse->rows, 'wrapping stays local too');

        // Enter loads the highlighted table.
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('users', $a->browse->selectedTable);
        $this->assertCount(3, $a->browse->rows, 'Enter must fetch exactly the highlighted table');
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

    public function testLoadTableRebuildsTheSharedResultGridView(): void
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
        // SQL-C14/client-014: one grid, one behaviour — Enter on a table does
        // NOT drop the shared view to a non-scrolling browse grid (the old
        // resultTable=null shape that made h/l lie). It REBUILDS the grid from
        // the preview rows: the query's `SELECT 1` column is replaced by the
        // table's own columns, so the pane always reflects the current rows.
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → admin
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → tables
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, '')); // load selected table
        $grid = $a->browse->resultTable;
        $this->assertNotNull($grid, 'browsing keeps the shared scrolling grid (h/l never dead)');
        $columns = array_map(static fn ($c): string => (string) $c, $grid->columns());
        $this->assertContains('title', $columns, 'the grid now shows the posts preview columns');
        $this->assertStringNotContainsString('alice', $grid->render(), 'cursor 0 is posts — no users rows leak in');
        $this->assertStringContainsString('hello', $grid->render(), 'the preview rebuilt from posts');
    }

    public function testAWideBrowsePreviewScrollsHorizontally(): void
    {
        // SQL-C14 acceptance (client-014): a wide preview used to cut mid-word
        // with `l` doing nothing — the h/l branch only engaged for query
        // results (browse nulled the grid). Browse now rides the same object,
        // so the scroll keys reach the LAST column through the real key path.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE wide (id INTEGER PRIMARY KEY, alpha TEXT, bravo TEXT, charlie TEXT, delta TEXT, echo_col TEXT, zulu TEXT)');
        $wide = static fn (string $mark): string => $mark . str_repeat('x', 40);
        $pdo->exec("INSERT INTO wide (alpha, bravo, charlie, delta, echo_col, zulu) VALUES ('{$wide('A')}', '{$wide('B')}', '{$wide('C')}', '{$wide('D')}', '{$wide('E')}', '{$wide('Z')}')");
        $db = new Database($pdo);

        $a = App::start($db); // loads the preview of `wide` synchronously
        $grid = $a->browse->resultTable;
        $this->assertNotNull($grid, 'the startup preview rides the shared grid');
        $this->assertTrue($grid->canScrollRight(), 'a 7-col preview of 40-char cells overflows the pane');
        $this->assertStringNotContainsString('Zxxx', $grid->render(), 'the last column starts off-screen');

        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, '')); // pane cycle → Rows
        for ($i = 0; $i < 12 && $a->browse->resultTable->canScrollRight(); $i++) {
            [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'l'));
            $this->assertNull($cmd, 'the scroll is a pure fold');
        }

        $this->assertFalse($a->browse->resultTable->canScrollRight(), 'the scroll walked to the end');
        $this->assertStringContainsString(
            'Zxxx',
            $a->browse->resultTable->render(),
            'the LAST column of a BROWSE preview is reachable via l (was a dead key)',
        );
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
     * On MySQL/Postgres, Enter on a table must NOT block on a synchronous
     * fetch (the asset_media freeze). Instead the model is marked loading,
     * the title follows the selection immediately, and an async Cmd is
     * returned to run the blob-safe fetch on the React loop. The rows arrive
     * later via {@see TableRowsLoadedMsg}. SQL-O10: the cursor move that
     * precedes Enter is local — it returns no Cmd at all.
     */
    public function testMysqlEnterLoadsRowsAsynchronously(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['posts', 'users'])->withSelectedTable('posts'),
        );
        [$moved, $moveCmd] = $app->update(new KeyMsg(KeyType::Down, ''));

        $this->assertSame(1, $moved->browse->tableCursor);
        $this->assertNull($moveCmd, 'cursor movement is a local edit — no fetch dispatched');
        $this->assertSame('posts', $moved->browse->selectedTable, 'selection follows Enter, not the cursor');
        $this->assertFalse($moved->browse->rowsLoading);

        [$next, $cmd] = $moved->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(1, $next->browse->tableCursor);
        $this->assertSame('users', $next->browse->selectedTable, 'title follows the selection immediately');
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
        [$next, $cmd] = $app->update(new TableRowsLoadedMsg('users', [['id' => 1], ['id' => 2]], null, 0));

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
        [$next, ] = $app->update(new TableRowsLoadedMsg('posts', [['id' => 99]], null, 0));

        $this->assertSame([], $next->browse->rows, 'stale result for a different table is dropped');
        $this->assertTrue($next->browse->rowsLoading, 'the in-flight load keeps its spinner');
    }

    public function testStaleSameTableGenerationIsIgnored(): void
    {
        // SQL-C12/client-012: re-selecting the SAME table bumps loadGen but
        // leaves selectedTable unchanged — the name guard cannot see that the
        // older leg is superseded. The fold's generation equality can.
        $browse = BrowseState::new()
            ->withTables(['users'])
            ->withSelectedTable('users')
            ->withRowsLoading(true)
            ->withIncrementedLoadGen();
        $app = new App(ConnectionState::new(new FakeDatabase(), Flavor::MySQL), $browse);

        [$next, ] = $app->update(new TableRowsLoadedMsg('users', [['id' => 99]], null, $browse->loadGen - 1));

        $this->assertSame([], $next->browse->rows, 'a superseded same-table leg stays dropped');
        $this->assertTrue($next->browse->rowsLoading, 'the current load keeps its spinner');
    }

    public function testTableRowsLoadedMsgErrorStashesError(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL),
            BrowseState::new()->withTables(['users'])->withSelectedTable('users')->withRowsLoading(true),
        );
        [$next, ] = $app->update(new TableRowsLoadedMsg('users', [], 'Table dropped', 0));

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
