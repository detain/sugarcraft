<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for ServerInfoCard rendering.
 */
final class ServerInfoCardTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new \SugarCraft\Query\Admin\ServerContext($this->db);
    }

    public function testRenderReturnsString(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Hostname', 'Value' => 'localhost'],
            ['Variable_name' => 'Socket', 'Value' => '/var/run/mysqld/mysqld.sock'],
            ['Variable_name' => 'Port', 'Value' => '3306'],
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertIsString($result);
    }

    public function testRenderContainsServerInformationTitle(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Hostname', 'Value' => 'testhost'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertStringContainsString('Server Information', $result);
    }

    public function testRenderContainsHostWhenSet(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Hostname', 'Value' => 'myhost.example.com'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertStringContainsString('myhost.example.com', $result);
    }

    public function testRenderContainsSocketWhenSet(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Socket', 'Value' => '/tmp/mysql.sock'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertStringContainsString('/tmp/mysql.sock', $result);
    }

    public function testRenderContainsPort(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Port', 'Value' => '3307'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertStringContainsString('3307', $result);
    }

    public function testRenderContainsVersion(): void
    {
        $this->db->setQueryResult([]);
        $this->db->setServerVersion('MySQL version 8.0.35');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        $this->assertStringContainsString('8.0.35', $result);
    }

    public function testRenderShowsUnknownForMissingData(): void
    {
        $this->db->setQueryResult([]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        // Should contain "Unknown" for unset fields
        $this->assertStringContainsString('Unknown', $result);
    }

    public function testNewCreatesInstanceWithContext(): void
    {
        $card = \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard::new($this->context);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ServerInfoCard::class, $card);
    }

    public function testRenderIncludesUptimeWhenSet(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '86400'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        // 86400 seconds = 1 day, should show "1d" or similar
        $this->assertStringContainsString('86400s', $result);
    }

    public function testRenderIncludesRunningSinceWhenUptimeSet(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $card = new \SugarCraft\Query\Admin\ServerStatus\ServerInfoCard($this->context);
        $result = $card->render();

        // Should contain a date formatted as Y-m-d H:i:s
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result);
    }
}
