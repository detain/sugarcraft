<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Connections\ConnectionCounters;
use SugarCraft\Query\Admin\StatusSnapshot;

final class ConnectionCountersTest extends TestCase
{
    public function testFromSnapshotExtractsVariables(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '50',
            'Threads_running' => '5',
            'Threads_cached' => '2',
            'Threads_created' => '100',
            'Connections' => '1000',
            'Aborted_clients' => '10',
            'Aborted_connects' => '5',
            'Connection_errors_accept' => '1',
            'Connection_errors_internal' => '2',
            'Connection_errors_max' => '0',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 200);

        $this->assertSame(50, $counters->threadsConnected);
        $this->assertSame(5, $counters->threadsRunning);
        $this->assertSame(2, $counters->threadsCached);
        $this->assertSame(100, $counters->threadsCreated);
        $this->assertSame(1000, $counters->connections);
        $this->assertSame(200, $counters->maxConnections);
        $this->assertSame(10, $counters->abortedClients);
        $this->assertSame(5, $counters->abortedConnects);
        $this->assertSame(3, $counters->connectionErrorsTotal);
    }

    public function testConnectionUsageRatioCalculation(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '80',
            'Threads_running' => '10',
            'Threads_cached' => '0',
            'Threads_created' => '500',
            'Connections' => '5000',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 100);

        $this->assertSame(0.8, $counters->connectionUsageRatio());
    }

    public function testIsConnectionUsageCritical(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '90',
            'Threads_running' => '10',
            'Threads_cached' => '0',
            'Threads_created' => '500',
            'Connections' => '5000',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 100);

        $this->assertTrue($counters->isConnectionUsageCritical());
    }

    public function testAbortedConnectionRate(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '50',
            'Threads_running' => '5',
            'Threads_cached' => '0',
            'Threads_created' => '100',
            'Connections' => '1000',
            'Aborted_clients' => '10',
            'Aborted_connects' => '100',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 100);

        $this->assertSame(0.1, $counters->abortedConnectionRate());
    }

    public function testAbortedConnectionRateZeroConnections(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '0',
            'Threads_running' => '0',
            'Threads_cached' => '0',
            'Threads_created' => '0',
            'Connections' => '0',
            'Aborted_clients' => '0',
            'Aborted_connects' => '0',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 100);

        $this->assertSame(0.0, $counters->abortedConnectionRate());
    }

    public function testConnectionUsageRatioCaching(): void
    {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => '50',
            'Threads_running' => '5',
            'Threads_cached' => '0',
            'Threads_created' => '100',
            'Connections' => '1000',
        ], microtime(true));

        $counters = ConnectionCounters::fromSnapshot($snapshot, 100);

        $ratio1 = $counters->connectionUsageRatio();
        $ratio2 = $counters->connectionUsageRatio();

        $this->assertSame($ratio1, $ratio2);
    }
}
