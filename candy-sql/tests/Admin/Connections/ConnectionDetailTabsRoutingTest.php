<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Db\Flavor;
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
            ['id' => '7', 'user' => 'app', 'thread_type' => 'FOREGROUND', 'thread_name' => 'thread/sql/one_connection'],
        ]);
        $page = ConnectionsPage::new(new ServerContext($db));
        $plain = static fn (ConnectionsPage $p): string => Scan::strip(\preg_replace('/\e\[[0-9;]*m/', '', $p->view()) ?? '');

        $this->assertNull($page->detailLines(), 'the panel starts closed');
        $closed = $plain($page);

        [$details] = $page->update(new KeyMsg(KeyType::Enter));
        $this->assertStringContainsString('thread_name: thread/sql/one_connection', $plain($details));

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
}
