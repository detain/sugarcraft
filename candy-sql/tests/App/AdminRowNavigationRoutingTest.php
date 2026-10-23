<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\Debug\DebugPage;
use SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Sql\Admin\Reports\ReportsPage;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Admin\Variables\VariablesPage;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * Routing precedence for the admin pane (docs-review-1023 M8).
 *
 * Before the fix App intercepted j/k/arrows for the sidebar walk on EVERY
 * admin pane, so the pages' own row-navigation handlers were dead code and
 * the footers advertised keys that did nothing. After the fix a focused page
 * implementing {@see RowNavigable} receives those keys; pages without the
 * marker keep the arrow-key sidebar walk; q and digits stay app-global.
 */
final class AdminRowNavigationRoutingTest extends TestCase
{
    private function adminApp(): App
    {
        // FakeDatabase answers the ServerContext fetches so pages behave as
        // they do against a real MySQL server (docs-review-1023 F1).
        $db = new FakeDatabase();
        $a = new App(ConnectionState::new($db, Flavor::MySQL, new ServerContext($db)));
        // Tables → Rows → Query → Admin
        [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Admin, $a->ui->pane);

        return $a;
    }

    /** @return array<string,string> pane-digit => class asserting row navigation */
    public static function rowNavigablePaneProvider(): array
    {
        return [
            'ProcessList' => ['1', ConnectionsPage::class],
            'Variables' => ['2', VariablesPage::class],
            'Debug' => ['4', DebugPage::class],
            'QueryStats' => ['5', ReportsPage::class],
            'TableStats' => ['7', ReportsPage::class],
            'PerfSchema' => ['8', PerfSchemaPage::class],
        ];
    }

    /**
     * @dataProvider rowNavigablePaneProvider
     */
    public function testJKeyReachesTheFocusedRowNavigablePage(string $digit, string $pageClass): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, $digit));
        $cursorBefore = $a->admin->cursor;

        [$a,] = $a->update(new KeyMsg(KeyType::Char, 'j'));

        // The sidebar must NOT move — j belonged to the page.
        $this->assertSame($cursorBefore, $a->admin->cursor, 'sidebar cursor moved under j');
        $this->assertSame($digit, (string) ($cursorBefore + 1));
        // And the page update must have been stored back (new page instance).
        $this->assertInstanceOf($pageClass, $a->admin->page);
        $this->assertInstanceOf(RowNavigable::class, $a->admin->page);
    }

    /**
     * @dataProvider rowNavigablePaneProvider
     */
    public function testDownArrowReachesTheFocusedRowNavigablePage(string $digit, string $pageClass): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, $digit));
        $cursorBefore = $a->admin->cursor;

        [$a,] = $a->update(new KeyMsg(KeyType::Down, ''));

        $this->assertSame($cursorBefore, $a->admin->cursor, 'sidebar cursor moved under Down');
        $this->assertInstanceOf($pageClass, $a->admin->page);
    }

    /**
     * @dataProvider rowNavigablePaneProvider
     */
    public function testKKeyReachesTheFocusedRowNavigablePage(string $digit, string $pageClass): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, $digit));
        $cursorBefore = $a->admin->cursor;

        [$a,] = $a->update(new KeyMsg(KeyType::Char, 'k'));

        $this->assertSame($cursorBefore, $a->admin->cursor, 'sidebar cursor moved under k');
        $this->assertInstanceOf($pageClass, $a->admin->page);
    }

    /** ServerStatus has no row navigation — the sidebar keeps the arrows. */
    public function testArrowsStillWalkTheSidebarOnNonRowNavigablePages(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertInstanceOf(ServerStatusPage::class, $a->adminPage());
        $cursorBefore = $a->admin->cursor;
        $this->assertSame(2, $cursorBefore, 'Status is sidebar row 3');

        [$a,] = $a->update(new KeyMsg(KeyType::Down, ''));

        $this->assertSame(3, $a->admin->cursor, 'Down must move the sidebar here');
        $this->assertSame(AdminPane::Debug, $a->admin->pane);
    }

    /**
     * q stays app-global on a row-navigable pane. update() intercepts it
     * BEFORE pane dispatch as a full quit (not a Tables return), so the page
     * must not observe the key.
     */
    public function testQuitStaysGlobalOnRowNavigablePages(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, '2'));
        [$a,] = $a->update(new KeyMsg(KeyType::Char, 'j'));

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));

        $this->assertNotNull($cmd, 'q must produce the global quit command');
        $this->assertSame(Pane::Admin, $a->ui->pane, 'q must not reach the page');
    }

    /** Digits stay app-global on a row-navigable pane: they switch panes. */
    public function testDigitsStayGlobalOnRowNavigablePages(): void
    {
        $a = $this->adminApp();
        [$a,] = $a->update(new KeyMsg(KeyType::Char, '2'));
        [$a,] = $a->update(new KeyMsg(KeyType::Char, 'j'));

        [$a,] = $a->update(new KeyMsg(KeyType::Char, '6'));

        $this->assertSame(AdminPane::Dashboard, $a->admin->pane);
        $this->assertSame(5, $a->admin->cursor);
    }

    /** Structural roster: exactly the five page classes carry the marker. */
    public function testRowNavigableRosterIsExactlyTheFiveListedPages(): void
    {
        $marker = new \ReflectionClass(RowNavigable::class);
        $expected = [
            ConnectionsPage::class,
            VariablesPage::class,
            ReportsPage::class,
            PerfSchemaPage::class,
            DebugPage::class,
        ];

        foreach ($expected as $class) {
            $this->assertTrue(
                $marker->isInterface() && is_subclass_of($class, RowNavigable::class),
                $class . ' must implement RowNavigable',
            );
        }

        // The two non-navigable pages must NOT carry it — that is what keeps
        // the arrow-key sidebar walk on them.
        foreach ([ServerStatusPage::class, \SugarCraft\Sql\Admin\Dashboard\DashboardPage::class] as $class) {
            $this->assertFalse(
                is_subclass_of($class, RowNavigable::class),
                $class . ' must not implement RowNavigable',
            );
        }
    }
}
