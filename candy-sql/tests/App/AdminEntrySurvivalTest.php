<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-C01 (client-001) regression: SQLite-Admin entry killed the TUI.
 *
 * The live crash was three default presses deep (Tab into Admin, first Tab
 * into a stats pane): adminPage() dereferenced EmptyServerContext::
 * connection(), which threw RuntimeException('Not supported') straight
 * through the render/fold path. Every pane must now build, paint, and
 * survive the app-level folds on a null-admin session.
 *
 * Can-fail proof: revert the NullDatabase sentinel (connection() throwing
 * again) and the QueryStats/TableStats legs go red.
 */
final class AdminEntrySurvivalTest extends TestCase
{
    private const TAB_TO_ADMIN = 3;

    private function adminApp(): App
    {
        $a = App::start(new FakeDatabase(), Flavor::Sqlite);
        for ($i = 0; $i < self::TAB_TO_ADMIN; $i++) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        }
        $this->assertSame(Pane::Admin, $a->ui->pane, 'test driver reached the Admin pane');
        return $a;
    }

    public function testEveryAdminPaneBuildsAndPaintsOnSqlite(): void
    {
        $a = $this->adminApp();
        foreach (AdminPane::orderedCases() as $index => $pane) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, (string) ($index + 1)));
            $this->assertSame($pane, $a->admin->pane, "digit " . ($index + 1) . " selects {$pane->name}");

            $page = $a->adminPage();
            $this->assertNotSame('', $page->view(), "{$pane->name} page paints");
            $this->assertNotSame('', $a->view(), "{$pane->name} full app frame paints");
        }
    }

    public function testReloadReportFoldSurvivesNullAdminSession(): void
    {
        $a = $this->adminApp();
        // Digit 5/7 land on the panes that used to crash at construction.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '5'));
        $this->adminPage($a);

        // Drive the fold arms the poll pipeline uses: an arrival and a drain
        // completion both re-enter page->update(ReloadReportMsg).
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg([], [], 1.0));
        [$a, ] = $a->update(new \SugarCraft\Sql\Core\Msg\AdminDrainCompletedMsg());
        $this->assertNotSame('', $a->view());
    }

    public function testKeyDelegationFoldSurvivesNullAdminSession(): void
    {
        $a = $this->adminApp();
        foreach (['r', 'j', 'k', '/', 'x'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertNotSame('', $a->view(), 'unhandled + reset keys degrade, never fatal');
    }

    private function adminPage(App $a): void
    {
        $this->assertNotNull($a->adminPage());
    }
}
