<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\EmptyServerContext;
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Admin\Variables\VariablesPage;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;

/**
 * D-C5 (2026-11-03): the quit keys are layer-scoped. Ctrl+C always quits;
 * Escape/q quit only from the browser panes; the Query pane must not lose a
 * draft to Escape; and a modal admin page owns every keystroke while open.
 */
final class QuitKeyRoutingTest extends TestCase
{
    private function app(Pane $pane = Pane::Query, ?object $page = null): App
    {
        $db = new SqliteDatabase(new PDO('sqlite::memory:'));
        $builder = App::builder()->withDb($db)->withFlavor(Flavor::Sqlite)->withPane($pane);
        if ($page !== null) {
            $builder = $builder->withAdminPage($page);
        }
        return $builder->build();
    }

    public function testEscapeInTheQueryPaneKeepsTheAppAliveAndOnPane(): void
    {
        $a = $this->app(Pane::Query);
        [, $cmd] = $a->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertNull($cmd, 'Escape in the Query pane must never quit');
        $this->assertSame(Pane::Query, $a->ui->pane);
    }

    public function testEscapeAndQStillQuitFromTheBrowserPanes(): void
    {
        foreach ([Pane::Tables, Pane::Rows] as $pane) {
            foreach ([new KeyMsg(KeyType::Escape, ''), new KeyMsg(KeyType::Char, 'q')] as $msg) {
                $a = $this->app($pane);
                [, $cmd] = $a->update($msg);
                $this->assertNotNull($cmd, "quit key on {$pane->value}");
                $this->assertInstanceOf(QuitMsg::class, $cmd(), "{$msg->string()} on {$pane->value} must quit");
            }
        }
    }

    public function testCtrlCQuitsFromEveryPaneIncludingQuery(): void
    {
        foreach (Pane::cases() as $pane) {
            $a = $this->app($pane);
            [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
            $this->assertNotNull($cmd, "ctrl+c on {$pane->value}");
            $this->assertInstanceOf(QuitMsg::class, $cmd(), "ctrl+c must quit from {$pane->value}");
        }
    }

    public function testEscapeOnTheAdminPaneIsDeliveredToThePageNotTheQuitGuard(): void
    {
        $page = new VariablesPage(new EmptyServerContext());
        $focused = (new ReflectionProperty(VariablesPage::class, 'searchFocused'))->getValue($page);
        $this->assertFalse($focused);

        $a = $this->app(Pane::Admin, $page);
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertNull($cmd, 'Escape on the admin pane must not quit the app');
        $this->assertSame(Pane::Admin, $a->ui->pane);
    }

    public function testModalPageOwnsTheQuitKeysWhileOpen(): void
    {
        $page = new VariablesPage(new EmptyServerContext());
        $this->assertFalse($page->isModal());
        (new ReflectionProperty(VariablesPage::class, 'editDialogPhase'))->setValue($page, 'input');
        $this->assertTrue($page->isModal(), 'edit dialog reports modal');

        $a = $this->app(Pane::Admin, $page);
        // 'q' must type into the dialog, not return to Tables or quit.
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNull($cmd);
        $this->assertSame(Pane::Admin, $a->ui->pane, 'modal page must keep the admin pane');
        $typed = (new ReflectionProperty(VariablesPage::class, 'editNewValue'))->getValue($a->adminPage());
        $this->assertStringContainsString('q', (string) $typed, 'q typed into the dialog value');

        // Escape closes the dialog instead of quitting.
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cmd, 'Escape cancels the dialog, never quits');
        $this->assertFalse($a->adminPage()->isModal(), 'dialog closed by Escape');
    }

    public function testModalFlagsOnTheOtherDialogOwningPages(): void
    {
        $connections = new ConnectionsPage(new EmptyServerContext());
        $this->assertFalse($connections->isModal());
        $armed = new \SugarCraft\Sql\Admin\Connections\ProcesslistResult(
            processId: 1, threadId: '1', user: 'root', host: 'localhost',
            database: 'test', command: 'Sleep', time: 0, state: '',
            info: '', connectionAttr: '', isPS: false, isBackground: false, infoTruncated: false,
        );
        (new ReflectionProperty(ConnectionsPage::class, 'pendingKill'))->setValue($connections, $armed);
        $this->assertTrue($connections->isModal(), 'armed kill confirmation reports modal');

        $status = new ServerStatusPage(new EmptyServerContext());
        $this->assertFalse($status->isModal());
        (new ReflectionProperty(ServerStatusPage::class, 'gtidDialog'))->setValue($status, true);
        $this->assertTrue($status->isModal(), 'open GTID dialog reports modal');
    }
}
