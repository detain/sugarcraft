<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for ReplicaStatusProvider.
 */
final class ReplicaStatusProviderTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new \SugarCraft\Query\Admin\ServerContext($this->db);
    }

    public function testNewCreatesInstance(): void
    {
        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $this->assertInstanceOf(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::class, $provider);
    }

    public function testFetchStatusReturnsEmptyArrayWhenNotConfigured(): void
    {
        $this->db->setQueryResult([]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $result = $provider->fetchStatus();

        $this->assertIsArray($result);
        $this->assertSame([], $result);
        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::NotConfigured, $provider->lastFetchKind());
    }

    public function testFetchStatusReturnsDataWhenConfigured(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'master.example.com', 'Master_Port' => '3306', 'Slave_IO_Running' => 'Yes', 'Slave_SQL_Running' => 'Yes'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $result = $provider->fetchStatus();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('master.example.com', $result[0]['Master_Host']);
        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::Configured, $provider->lastFetchKind());
    }

    public function testIsReplicaConfiguredReturnsFalseWhenNoData(): void
    {
        $this->db->setQueryResult([]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $this->assertFalse($provider->isReplicaConfigured());
    }

    public function testIsReplicaConfiguredReturnsTrueWhenDataPresent(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'master.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $this->assertTrue($provider->isReplicaConfigured());
    }

    public function testRefreshReturnsNewInstanceWithClearedCache(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'master1.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $firstResult = $provider->fetchStatus();

        // Change the data
        $this->db->setQueryResult([
            ['Master_Host' => 'master2.example.com'],
        ]);

        // Without refresh, should still get cached result
        $cachedResult = $provider->fetchStatus();
        $this->assertSame($firstResult, $cachedResult);

        // With refresh, should get new result
        $refreshed = $provider->refresh();
        $newResult = $refreshed->fetchStatus();

        $this->assertNotSame($firstResult, $newResult);
        $this->assertSame('master2.example.com', $newResult[0]['Master_Host']);
    }

    public function testFetchStatusCachesOnFirstCall(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'cached.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $first = $provider->fetchStatus();

        // Change the database result
        $this->db->setQueryResult([
            ['Master_Host' => 'different.example.com'],
        ]);

        // Second call should return cached result
        $second = $provider->fetchStatus();

        $this->assertSame($first, $second);
        $this->assertSame('cached.example.com', $first[0]['Master_Host']);
    }

    public function testFetchStatusReturnsEmptyOnError1227(): void
    {
        // PDOException constructor takes (message, code) where code must be int or string depending on driver
        // Using error code 1227 which is "command denied" in MySQL
        $this->db->setQueryThrows(new \PDOException('REPLICATION CLIENT command denied', 1227));

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $result = $provider->fetchStatus();

        // Error 1227 (command denied) returns empty rows with PermissionDenied kind
        $this->assertIsArray($result);
        $this->assertSame([], $result);
        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::PermissionDenied, $provider->lastFetchKind());
    }

    public function testIsReplicaConfiguredIsCached(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'master.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $first = $provider->isReplicaConfigured();

        // Change the data
        $this->db->setQueryResult([]);

        // Should still return cached value
        $second = $provider->isReplicaConfigured();

        $this->assertSame($first, $second);
        $this->assertTrue($second);
    }

    public function testRefreshClearsIsConfiguredCache(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'master.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $provider->isReplicaConfigured(); // Cache it

        // Change to empty
        $this->db->setQueryResult([]);

        // Without refresh, should still return cached true
        $this->assertTrue($provider->isReplicaConfigured());

        // With refresh, should return false
        $refreshed = $provider->refresh();
        $this->assertFalse($refreshed->isReplicaConfigured());
    }

    public function testFetchStatusHandlesMysql8ReplicaSyntax(): void
    {
        // MySQL 8 uses Source_* column names instead of Master_*
        $this->db->setQueryResult([
            ['Source_Host' => 'mysql8-master.example.com', 'Source_Port' => '3306', 'Replica_IO_Running' => 'Yes', 'Replica_SQL_Running' => 'Yes'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $result = $provider->fetchStatus();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('mysql8-master.example.com', $result[0]['Source_Host']);
        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::Configured, $provider->lastFetchKind());
    }

    public function testLastFetchKindReturnsNotConfiguredOnEmptyResult(): void
    {
        $this->db->setQueryResult([]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $provider->fetchStatus();

        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::NotConfigured, $provider->lastFetchKind());
    }

    public function testLastFetchKindReturnsConfiguredOnRows(): void
    {
        $this->db->setQueryResult([
            ['Master_Host' => 'replica.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $provider->fetchStatus();

        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::Configured, $provider->lastFetchKind());
    }

    public function testLastFetchKindReturnsPermissionDeniedOnError1227(): void
    {
        $this->db->setQueryThrows(new \PDOException('command denied', 1227));

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $provider->fetchStatus();

        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::PermissionDenied, $provider->lastFetchKind());
    }

    public function testLastFetchKindReturnsErrorOnUnexpectedException(): void
    {
        $this->db->setQueryThrows(new \PDOException('connection lost', 2006));

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $provider->fetchStatus();

        $this->assertSame(\SugarCraft\Query\Admin\ServerStatus\ReplicaStatusKind::Error, $provider->lastFetchKind());
    }

    public function testFetchStatusReturnsAllChannels(): void
    {
        $this->db->setQueryResult([
            ['Channel_name' => 'channel_1', 'Master_Host' => 'master1.example.com'],
            ['Channel_name' => 'channel_2', 'Master_Host' => 'master2.example.com'],
        ]);

        $provider = \SugarCraft\Query\Admin\ServerStatus\ReplicaStatusProvider::new($this->context);
        $result = $provider->fetchStatus();

        $this->assertCount(2, $result);
        $this->assertSame('master1.example.com', $result[0]['Master_Host']);
        $this->assertSame('master2.example.com', $result[1]['Master_Host']);
    }
}
