<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Admin\Providers\MysqlAdminProvider;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for MysqlAdminProvider wrapping ServerContext.
 */
final class MysqlAdminProviderTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContext $context;
    private MysqlAdminProvider $provider;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new ServerContext($this->db);
        $this->provider = MysqlAdminProvider::new($this->context);
    }

    public function testImplementsAdminProviderInterface(): void
    {
        $this->assertInstanceOf(AdminProviderInterface::class, $this->provider);
    }

    public function testFlavorReturnsMySQL(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.33');
        $this->assertSame(Flavor::MySQL, $this->provider->flavor());
    }

    public function testFetchStatusVariablesDelegatesToContext(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $result = $this->provider->fetchStatusVariables();

        $this->assertSame('3600', $result['Uptime']);
        $this->assertSame('5', $result['Threads_connected']);
    }

    public function testFetchServerVariablesDelegatesToContext(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '200'],
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);

        $result = $this->provider->fetchServerVariables();

        $this->assertSame('200', $result['max_connections']);
        $this->assertSame('8.0.33', $result['version']);
    }

    public function testFetchProcesslistReturnsNormalizedRows(): void
    {
        $this->db->setQueryResult([
            [
                'Id' => 42,
                'User' => 'app_user',
                'Host' => 'localhost',
                'db' => 'myapp',
                'Command' => 'Query',
                'Time' => 5,
                'State' => 'executing',
                'Info' => 'SELECT * FROM users',
            ],
        ]);

        $result = $this->provider->fetchProcesslist();

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['processId']);
        $this->assertSame('app_user', $result[0]['user']);
        $this->assertSame('localhost', $result[0]['host']);
        $this->assertSame('myapp', $result[0]['database']);
        $this->assertSame('Query', $result[0]['command']);
        $this->assertSame(5, $result[0]['time']);
        $this->assertSame('executing', $result[0]['state']);
        $this->assertSame('SELECT * FROM users', $result[0]['info']);
        $this->assertSame([], $result[0]['connectionAttr']);
    }

    public function testFetchProcesslistReturnsEmptyOnError(): void
    {
        $this->db->setQueryThrows(new \PDOException('Access denied', 42000));

        $result = $this->provider->fetchProcesslist();

        $this->assertSame([], $result);
    }

    public function testMaxConnectionsFromServerVariables(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '250'],
        ]);

        // ServerVariables is called; max_connections is derived from it
        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '250'],
        ]);

        $this->assertSame(250, $this->provider->maxConnections());
    }

    public function testMaxConnectionsDefaultsTo151WhenNotSet(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);

        $this->assertSame(151, $this->provider->maxConnections());
    }

    public function testStatusVariablesTsReturnsTimestamp(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $before = microtime(true);
        $this->provider->fetchStatusVariables();
        $after = microtime(true);

        $ts = $this->provider->statusVariablesTs();
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testWasResetDelegatesToContext(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $this->assertFalse($this->provider->wasReset());
    }

    public function testRefreshDelegatesToContext(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.33'],
        ]);

        $this->provider->fetchServerVariables();

        $this->db->setQueryResult([
            ['Variable_name' => 'version', 'Value' => '8.0.36'],
        ]);

        $this->provider->refresh();
        $this->provider->fetchServerVariables();

        $this->assertSame('8.0.36', $this->provider->fetchServerVariables()['version']);
    }

    public function testNewFactoryCreatesInstance(): void
    {
        $provider = MysqlAdminProvider::new($this->context);

        $this->assertInstanceOf(MysqlAdminProvider::class, $provider);
        $this->assertInstanceOf(AdminProviderInterface::class, $provider);
    }
}
