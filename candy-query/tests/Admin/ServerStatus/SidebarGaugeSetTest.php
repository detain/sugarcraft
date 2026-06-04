<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerStatus\GaugeType;
use SugarCraft\Query\Admin\ServerStatus\SidebarGauge;
use SugarCraft\Query\Admin\ServerStatus\SidebarGaugeSet;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Tests for SidebarGaugeSet.
 */
final class SidebarGaugeSetTest extends TestCase
{
    private FakeServerContext $context;

    protected function setUp(): void
    {
        $this->context = new FakeServerContext();
    }

    public function testNewCreatesInstance(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $this->assertInstanceOf(SidebarGaugeSet::class, $set);
    }

    public function testNewWithContextPopulatesGauges(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $gauges = $set->gauges();

        $this->assertIsArray($gauges);
        // Should have 5 standard gauges (Connections, Traffic, KeyEff, QPS, InnoDB)
        $this->assertCount(5, $gauges);
    }

    public function testCpuGaugeIsNullWhenMaxConnectionsUnavailable(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $this->assertNull($set->cpuGauge());
    }

    public function testCpuGaugeIsPresentWhenMaxConnectionsAvailable(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '50');

        $set = SidebarGaugeSet::new($this->context);

        $this->assertNotNull($set->cpuGauge());
        $this->assertInstanceOf(SidebarGauge::class, $set->cpuGauge());
    }

    public function testCpuGaugeRatioIsCorrect(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '50');

        $set = SidebarGaugeSet::new($this->context);

        $this->assertEquals(0.5, $set->cpuGauge()->ratio());
    }

    public function testPollReturnsNewInstance(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $polled = $set->poll();

        $this->assertNotSame($set, $polled);
    }

    public function testPollPreservesGauges(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $polled = $set->poll();

        $this->assertCount(count($set->gauges()), $polled->gauges());
    }

    public function testPollPreservesCpuGauge(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '50');

        $set = SidebarGaugeSet::new($this->context);
        $polled = $set->poll();

        $this->assertNotNull($polled->cpuGauge());
    }

    public function testViewReturnsString(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $view = $set->view();

        $this->assertIsString($view);
    }

    public function testViewContainsServerMetricsHeader(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $view = $set->view();

        $this->assertStringContainsString('Server Metrics', $view);
    }

    public function testViewContainsAllGaugeLabels(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $view = $set->view();

        $this->assertStringContainsString('Connections', $view);
        $this->assertStringContainsString('Traffic', $view);
        $this->assertStringContainsString('Key Eff', $view);
        $this->assertStringContainsString('QPS', $view);
        $this->assertStringContainsString('InnoDB', $view);
    }

    public function testViewIncludesCpuGaugeWhenAvailable(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '50');

        $set = SidebarGaugeSet::new($this->context);
        $view = $set->view();

        // CPU gauge renders as a circular gauge with percentage " 50% "
        $this->assertStringContainsString(' 50% ', $view);
    }

    public function testConnectionsRatioIsComputed(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '25');

        $set = SidebarGaugeSet::new($this->context);
        $connectionsGauge = null;

        foreach ($set->gauges() as $gauge) {
            if ($gauge->type() === GaugeType::Connections) {
                $connectionsGauge = $gauge;
                break;
            }
        }

        $this->assertNotNull($connectionsGauge);
        $this->assertEquals(0.25, $connectionsGauge->ratio());
    }

    public function testPollUpdatesConnectionsRatio(): void
    {
        $this->context->setServerVariable('max_connections', '100');
        $this->context->setStatusVariable('Threads_connected', '25');

        $set = SidebarGaugeSet::new($this->context);

        // Simulate increased connections
        $this->context->setStatusVariable('Threads_connected', '75');

        $polled = $set->poll();
        $connectionsGauge = null;

        foreach ($polled->gauges() as $gauge) {
            if ($gauge->type() === GaugeType::Connections) {
                $connectionsGauge = $gauge;
                break;
            }
        }

        $this->assertNotNull($connectionsGauge);
        $this->assertEquals(0.75, $connectionsGauge->ratio());
    }

    public function testInnoDBRatioIsComputed(): void
    {
        // Set up InnoDB buffer pool stats
        $this->context->setStatusVariable('Innodb_buffer_pool_pages_free', '100');
        $this->context->setStatusVariable('Innodb_buffer_pool_pages_total', '400');

        $set = SidebarGaugeSet::new($this->context);
        $innodbGauge = null;

        foreach ($set->gauges() as $gauge) {
            if ($gauge->type() === GaugeType::InnoDB) {
                $innodbGauge = $gauge;
                break;
            }
        }

        $this->assertNotNull($innodbGauge);
        // (400 - 100) / 400 = 0.75
        $this->assertEquals(0.75, $innodbGauge->ratio());
    }

    public function testInnoDBRatioUsesFullWhenNoData(): void
    {
        // No InnoDB stats available
        $set = SidebarGaugeSet::new($this->context);
        $innodbGauge = null;

        foreach ($set->gauges() as $gauge) {
            if ($gauge->type() === GaugeType::InnoDB) {
                $innodbGauge = $gauge;
                break;
            }
        }

        $this->assertNotNull($innodbGauge);
        // Should default to 0.5 when no data
        $this->assertEquals(0.5, $innodbGauge->ratio());
    }

    public function testGaugesReturnsAllStandardGauges(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $types = array_map(
            fn(SidebarGauge $g) => $g->type(),
            $set->gauges()
        );

        $this->assertContains(GaugeType::Connections, $types);
        $this->assertContains(GaugeType::Traffic, $types);
        $this->assertContains(GaugeType::KeyEfficiency, $types);
        $this->assertContains(GaugeType::Qps, $types);
        $this->assertContains(GaugeType::InnoDB, $types);
    }

    public function testViewReturnsNonEmptyString(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $view = $set->view();

        $this->assertNotEmpty($view);
    }

    public function testPreviousRatesIsNullOnNew(): void
    {
        $set = SidebarGaugeSet::new($this->context);
        $this->assertNull($set->previousRates());
    }
}

/**
 * Fake ServerContextInterface for testing SidebarGaugeSet.
 */
final class FakeServerContext implements ServerContextInterface
{
    /** @var array<string, string> */
    private array $serverVariables = [];

    /** @var array<string, string> */
    private array $statusVariables = [];

    private float $ts = 0.0;

    public function setServerVariable(string $name, string $value): void
    {
        $this->serverVariables[$name] = $value;
    }

    public function setStatusVariable(string $name, string $value): void
    {
        $this->statusVariables[$name] = $value;
    }

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        return $this->serverVariables;
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        return $this->statusVariables;
    }

    public function statusVariablesTs(): float
    {
        return $this->ts;
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        return [];
    }

    public function version(): Version
    {
        return Version::parse('8.0.33');
    }

    public function flavor(): Flavor
    {
        return Flavor::MySQL;
    }

    public function versionString(): string
    {
        return '8.0.33';
    }

    public function password(): string { return ''; }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
        $this->ts = microtime(true);
    }
}
