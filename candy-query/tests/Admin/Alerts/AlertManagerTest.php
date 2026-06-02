<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Alerts;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Alerts\Alert;
use SugarCraft\Query\Admin\Alerts\AlertManager;
use SugarCraft\Query\Admin\Alerts\AlertThresholds;
use SugarCraft\Query\Admin\Alerts\AlertNotifier;
use SugarCraft\Query\Admin\Alerts\Severity;
use SugarCraft\Query\Admin\Connections\ConnectionCounters;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * @covers \SugarCraft\Query\Admin\Alerts\AlertManager
 */
final class AlertManagerTest extends TestCase
{
    public function testNewReturnsDefaultThresholdsAndNoNotifier(): void
    {
        $manager = AlertManager::new();

        $this->assertInstanceOf(AlertThresholds::class, $manager->thresholds());
        $this->assertNull($manager->notifier());
    }

    public function testWithThresholdsReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = AlertThresholds::strict();

        $m1 = AlertManager::new();
        $m2 = $m1->withThresholds($t2);

        // Original unchanged
        $this->assertSame($t1->connectionCriticalThreshold(), $m1->thresholds()->connectionCriticalThreshold());
        // New value set
        $this->assertSame($t2->connectionCriticalThreshold(), $m2->thresholds()->connectionCriticalThreshold());
    }

    public function testWithNotifierReturnsNewInstance(): void
    {
        $notifier = AlertNotifier::new();

        $m1 = AlertManager::new();
        $m2 = $m1->withNotifier($notifier);

        $this->assertNull($m1->notifier());
        $this->assertSame($notifier, $m2->notifier());
    }

    public function testCheckConnectionUsageReturnsEmptyArrayWhenBelowWarning(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 50,
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertSame([], $alerts);
    }

    public function testCheckConnectionUsageFiresWarningWhenAboveWarningThreshold(): void
    {
        // Warning at 60%, so 100/151 = 66.2% should trigger warning
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 100,
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertArrayHasKey('connection_warning', $alerts);
        $alert = $alerts['connection_warning'];
        $this->assertSame('connection_usage', $alert->metric);
        $this->assertTrue($alert->isWarning());
    }

    public function testCheckConnectionUsageFiresCriticalWhenAboveCriticalThreshold(): void
    {
        // Critical at 80%, so 130/151 = 86.1% should trigger critical
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 130,
            threadsRunning: 10,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertArrayHasKey('connection_critical', $alerts);
        $alert = $alerts['connection_critical'];
        $this->assertSame('connection_usage', $alert->metric);
        $this->assertTrue($alert->isCritical());
    }

    public function testCheckConnectionUsageFiresAbortedRateWarning(): void
    {
        // Aborted rate at 5%, so 60/1000 = 6% should trigger warning
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 50,
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 60,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertArrayHasKey('aborted_rate', $alerts);
        $alert = $alerts['aborted_rate'];
        $this->assertSame('aborted_rate', $alert->metric);
        $this->assertTrue($alert->isWarning());
    }

    public function testCheckConnectionUsageFiresThreadRunningWarning(): void
    {
        // Thread running at 50%, so 80/151 = 53% should trigger warning
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 50,
            threadsRunning: 80, // High thread running relative to max
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertArrayHasKey('thread_running', $alerts);
        $alert = $alerts['thread_running'];
        $this->assertSame('thread_running', $alert->metric);
        $this->assertTrue($alert->isWarning());
    }

    public function testCheckConnectionUsageWithWatchedMetricsFilter(): void
    {
        $manager = AlertManager::new()->withThresholds(
            AlertThresholds::default()->withWatchedMetrics(['aborted_rate'])
        );

        // Both connection usage and aborted rate would fire, but we only watch aborted_rate
        $counters = $this->createConnectionCounters(
            threadsConnected: 130, // Would trigger connection_critical
            threadsRunning: 80,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 60, // Would trigger aborted_rate
        );

        $alerts = $manager->checkConnectionUsage($counters);

        // Only aborted_rate should be present since we watch only that metric
        $this->assertArrayNotHasKey('connection_critical', $alerts);
        $this->assertArrayHasKey('aborted_rate', $alerts);
    }

    public function testCheckConnectionUsageWarningTakesPrecedenceOverCritical(): void
    {
        // When ratio is between warning (60%) and critical (80%),
        // only warning should fire, not critical
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 100, // 66.2% - above warning, below critical
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertArrayHasKey('connection_warning', $alerts);
        $this->assertArrayNotHasKey('connection_critical', $alerts);
    }

    public function testCheckAllMetricsWithSlowQueryThreshold(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $statusVars = [];
        $serverVars = ['long_query_time' => '10.0'];

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        $this->assertArrayHasKey('slow_query', $alerts);
        $alert = $alerts['slow_query'];
        $this->assertSame('slow_query', $alert->metric);
        $this->assertTrue($alert->isWarning());
    }

    public function testCheckAllMetricsIgnoresZeroSlowQueryTime(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $statusVars = [];
        $serverVars = ['long_query_time' => '0.0'];

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        $this->assertArrayNotHasKey('slow_query', $alerts);
    }

    public function testCheckAllMetricsWithConnectionErrors(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $statusVars = ['Connection_errors_total' => '150'];
        $serverVars = ['max_connections' => '151'];

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        $this->assertArrayHasKey('connection_errors', $alerts);
        $alert = $alerts['connection_errors'];
        $this->assertSame('connection_errors', $alert->metric);
    }

    public function testCheckAllMetricsWithMaxConnectionsUsage(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $statusVars = ['Threads_connected' => '120'];
        $serverVars = ['max_connections' => '151'];

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        // 120/151 = 79.5% - above warning (60%) but below critical (80%)
        // Actually above critical in default thresholds (80% is critical)
        // 120/151 = 79.5% < 80% so should be warning
        $this->assertArrayHasKey('max_connections_usage', $alerts);
        $alert = $alerts['max_connections_usage'];
        $this->assertSame('max_connections', $alert->metric);
    }

    public function testCheckAndNotifyReturnsAlertsAndUpdatesNotifier(): void
    {
        $notifier = AlertNotifier::new(fn(): \SugarCraft\Toast\Toast => \SugarCraft\Toast\Toast::new(50))->withMuted(false);
        $counters = $this->createConnectionCounters(
            threadsConnected: 130, // 86.1% - above critical
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $manager = AlertManager::new()
            ->withThresholds(AlertThresholds::default())
            ->withNotifier($notifier);

        $result = $manager->checkAndDispatch($counters);

        // Should have fired connection_critical alert
        $this->assertArrayHasKey('connection_critical', $result['alerts']);
        // The notifier should have been updated
        $this->assertTrue($result['notifier']->hasActiveAlert());
    }

    public function testCheckConnectionUsageEdgeCaseAtExactlyThreshold(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        // Exactly at critical threshold: 120.8/151 = 0.8
        $counters = $this->createConnectionCounters(
            threadsConnected: 121,
            threadsRunning: 5,
            maxConnections: 151,
            connections: 1000,
            abortedConnects: 10,
        );

        $alerts = $manager->checkConnectionUsage($counters);

        // Should fire critical since 121/151 = 0.801 > 0.8
        $this->assertArrayHasKey('connection_critical', $alerts);
    }

    public function testCheckConnectionUsageZeroMaxConnections(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $counters = $this->createConnectionCounters(
            threadsConnected: 50,
            threadsRunning: 5,
            maxConnections: 0, // Edge case: zero max connections
            connections: 1000,
            abortedConnects: 10,
        );

        // Should not throw, should return empty (div protection)
        $alerts = $manager->checkConnectionUsage($counters);

        $this->assertSame([], $alerts);
    }

    public function testCheckAllMetricsWithEmptyVariables(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());

        $alerts = $manager->checkAllMetrics([], []);

        // Should not throw, should return appropriate alerts for missing data
        $this->assertIsArray($alerts);
    }

    public function testCheckAllMetricsIgnoresConnectionErrorsWhenBelowThreshold(): void
    {
        $manager = AlertManager::new()->withThresholds(AlertThresholds::default());
        $statusVars = ['Connection_errors_total' => '50']; // Below 100 threshold
        $serverVars = ['max_connections' => '151'];

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        $this->assertArrayNotHasKey('connection_errors', $alerts);
    }

    public function testCheckAllMetricsFilteredByWatchedMetrics(): void
    {
        $manager = AlertManager::new()->withThresholds(
            AlertThresholds::default()->withWatchedMetrics(['slow_query'])
        );
        $statusVars = ['Connection_errors_total' => '200']; // Would trigger if watched
        $serverVars = ['long_query_time' => '10.0']; // Would trigger if watched

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        // Only slow_query should be present since we only watch that
        $this->assertArrayNotHasKey('connection_errors', $alerts);
        $this->assertArrayHasKey('slow_query', $alerts);
    }

    /**
     * Helper to create a ConnectionCounters for testing.
     */
    private function createConnectionCounters(
        int $threadsConnected,
        int $threadsRunning,
        int $maxConnections,
        int $connections,
        int $abortedConnects,
    ): ConnectionCounters {
        $snapshot = new StatusSnapshot([
            'Threads_connected' => (string) $threadsConnected,
            'Threads_running' => (string) $threadsRunning,
            'Connections' => (string) $connections,
            'Aborted_connects' => (string) $abortedConnects,
            'Aborted_clients' => '0',
            'Threads_cached' => '0',
            'Threads_created' => '0',
        ], microtime(true));

        return ConnectionCounters::fromSnapshot($snapshot, $maxConnections);
    }
}
