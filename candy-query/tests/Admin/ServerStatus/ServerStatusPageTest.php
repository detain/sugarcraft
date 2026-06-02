<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for ServerStatusPage.
 */
final class ServerStatusPageTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new \SugarCraft\Query\Admin\ServerContext($this->db);
    }

    public function testExtendsPageBase(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $this->assertInstanceOf(PageBase::class, $page);
    }

    public function testViewReturnsString(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertIsString($result);
    }

    public function testViewContainsServerStatusHeader(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Server Status', $result);
    }

    public function testViewContainsServerInformation(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Hostname', 'Value' => 'localhost'],
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Server Information', $result);
    }

    public function testViewContainsFeaturesPanel(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Features', $result);
    }

    public function testViewContainsDirectoriesPanel(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Directories', $result);
    }

    public function testViewContainsSslPanel(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('SSL', $result);
    }

    public function testViewContainsReplicationPanel(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Replication', $result);
    }

    public function testViewContainsFirewallPanel(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Firewall', $result);
    }

    public function testViewContainsRefreshQuitHints(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('[r] refresh', $result);
        $this->assertStringContainsString('[q] quit', $result);
    }

    public function testNewCreatesInstanceWithContext(): void
    {
        $page = \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::new($this->context);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::class, $page);
    }

    public function testUpdateReturnsSelfForNonKeyMsg(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $msg = new \SugarCraft\Core\Msg\MouseMsg(
            0, 0,
            \SugarCraft\Core\MouseButton::Left,
            \SugarCraft\Core\MouseAction::Press
        );

        $result = $page->update($msg);

        $this->assertSame($page, $result[0]);
        $this->assertNull($result[1]);
    }

    public function testUpdateReturnsWithRefreshForRKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'r',
            false,
            false,
            false
        );

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::class, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateReturnsWithQuitForQKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'q',
            false,
            false,
            false
        );

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::class, $newPage);
        $this->assertNull($cmd);
    }

    public function testWithRefreshReturnsNewInstance(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $refreshed = $page->withRefresh();

        $this->assertNotSame($page, $refreshed);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::class, $refreshed);
    }

    public function testWithQuitReturnsNewInstance(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $quit = $page->withQuit();

        $this->assertNotSame($page, $quit);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerStatusPage::class, $quit);
    }

    public function testReplicaProviderAccessor(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);
        $provider = $page->replicaProvider();

        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::class, $provider);
    }

    public function testTristateRendersYesCorrectly(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);

        $result = $page->tristate(true);

        $this->assertStringContainsString('Yes', $result);
    }

    public function testTristateRendersNoCorrectly(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);

        $result = $page->tristate(false);

        $this->assertStringContainsString('No', $result);
    }

    public function testTristateRendersUnknownForNull(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);

        $result = $page->tristate(null);

        $this->assertStringContainsString('Unknown', $result);
    }

    public function testTristateAcceptsStringYesNoOn(): void
    {
        $page = new \SugarCraft\Query\Admin\ServerStatus\ServerStatusPage($this->context);

        $this->assertStringContainsString('Yes', $page->tristate('YES'));
        $this->assertStringContainsString('Yes', $page->tristate('ON'));
        $this->assertStringContainsString('No', $page->tristate('NO'));
        $this->assertStringContainsString('No', $page->tristate('OFF'));
    }
}
