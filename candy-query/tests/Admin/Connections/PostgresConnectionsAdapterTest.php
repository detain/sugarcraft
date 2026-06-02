<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Admin\Connections\PostgresConnectionsAdapter;
use SugarCraft\Query\Db\Flavor;

/**
 * Tests for PostgresConnectionsAdapter stub functionality.
 */
final class PostgresConnectionsAdapterTest extends TestCase
{
    private FakePostgresAdminProvider $provider;
    private PostgresConnectionsAdapter $adapter;

    protected function setUp(): void
    {
        $this->provider = new FakePostgresAdminProvider();
        $this->adapter = PostgresConnectionsAdapter::new($this->provider);
    }

    public function testCountersReturnsZeroValues(): void
    {
        $counters = $this->adapter->counters();

        $this->assertSame(0, $counters->threadsConnected);
        $this->assertSame(0, $counters->threadsRunning);
        $this->assertSame(0, $counters->threadsCached);
        $this->assertSame(0, $counters->threadsCreated);
        $this->assertSame(0, $counters->connections);
        $this->assertSame(100, $counters->maxConnections); // From fake provider
        $this->assertSame(0, $counters->abortedClients);
        $this->assertSame(0, $counters->abortedConnects);
        $this->assertSame(0, $counters->connectionErrorsTotal);
    }

    public function testStubProcesslistReturnsEmptyListWhenProviderReturnsEmpty(): void
    {
        $this->provider->setProcesslistResult([]);

        $result = $this->adapter->stubProcesslist();

        $this->assertSame([], $result);
    }

    public function testStubProcesslistReturnsNormalizedProcesslistResults(): void
    {
        $this->provider->setProcesslistResult([
            [
                'processId' => 12345,
                'user' => 'app_user',
                'host' => '192.168.1.100',
                'database' => 'myapp',
                'command' => 'active',
                'time' => 30,
                'state' => 'active',
                'info' => 'SELECT * FROM orders',
                'connectionAttr' => ['application_name' => 'psql'],
            ],
        ]);

        $result = $this->adapter->stubProcesslist();

        $this->assertCount(1, $result);
        $this->assertSame(12345, $result[0]->processId);
        $this->assertSame('app_user', $result[0]->user);
        $this->assertSame('192.168.1.100', $result[0]->host);
        $this->assertSame('myapp', $result[0]->database);
        $this->assertSame('active', $result[0]->command);
        $this->assertSame(30, $result[0]->time);
    }

    public function testNoticeReturnsGuidanceString(): void
    {
        $notice = $this->adapter->notice();

        $this->assertSame('Postgres process list requires pg_stat_activity GRANT.', $notice);
    }

    public function testSupportsFlavorReturnsTrueForPostgres(): void
    {
        $this->assertTrue($this->adapter->supportsFlavor());
    }

    public function testSupportsFlavorReturnsFalseForNonPostgres(): void
    {
        $mysqlProvider = new class implements AdminProviderInterface {
            public function flavor(): Flavor { return Flavor::MySQL; }
            public function fetchStatusVariables(): array { return []; }
            public function fetchServerVariables(): array { return []; }
            public function fetchProcesslist(): array { return []; }
            public function maxConnections(): int { return 151; }
            public function statusVariablesTs(): float { return microtime(true); }
            public function wasReset(): bool { return false; }
            public function refresh(): void {}
        };

        $adapter = PostgresConnectionsAdapter::new($mysqlProvider);

        $this->assertFalse($adapter->supportsFlavor());
    }

    public function testNewFactoryCreatesInstance(): void
    {
        $adapter = PostgresConnectionsAdapter::new($this->provider);

        $this->assertInstanceOf(PostgresConnectionsAdapter::class, $adapter);
    }

    public function testCountersCachesResult(): void
    {
        $first = $this->adapter->counters();
        $second = $this->adapter->counters();

        $this->assertSame($first, $second);
    }
}

/**
 * Fake AdminProviderInterface for testing PostgresConnectionsAdapter.
 */
final class FakePostgresAdminProvider implements AdminProviderInterface
{
    /** @var list<array<string, mixed>> */
    private array $processlistResult = [];

    public function setProcesslistResult(array $result): void
    {
        $this->processlistResult = $result;
    }

    public function flavor(): Flavor
    {
        return Flavor::Postgres;
    }

    public function fetchStatusVariables(): array
    {
        return [];
    }

    public function fetchServerVariables(): array
    {
        return [];
    }

    public function fetchProcesslist(): array
    {
        return $this->processlistResult;
    }

    public function maxConnections(): int
    {
        return 100;
    }

    public function statusVariablesTs(): float
    {
        return microtime(true);
    }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
    }
}
