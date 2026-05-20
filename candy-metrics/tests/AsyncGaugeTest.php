<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Instrument\AsyncGauge;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class AsyncGaugeTest extends TestCase
{
    public function testObserveCallsCallbackAndRecordsValue(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $gauge = new AsyncGauge($r, 'memory.rss', 'Resident set size in MB', fn() => 512.5);
        $gauge->observe();
        $this->assertSame(512.5, $b->asyncGaugeValue('memory.rss'));
    }

    public function testObserveCanReportDifferingValues(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $gauge = new AsyncGauge($r, 'cpu.usage', 'CPU usage %', fn() => 100.0);
        $gauge->observe();
        $this->assertSame(100.0, $b->asyncGaugeValue('cpu.usage'));
        $gauge = new AsyncGauge($r, 'cpu.usage', 'CPU usage %', fn() => 85.0);
        $gauge->observe();
        $this->assertSame(85.0, $b->asyncGaugeValue('cpu.usage'));
    }

    public function testObserveWithTags(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $gauge = new AsyncGauge($r, 'disk.usage', 'Disk usage %', fn() => 73.0, ['mount' => '/']);
        $gauge->observe();
        $this->assertSame(73.0, $b->asyncGaugeValue('disk.usage', ['mount' => '/']));
    }

    public function testNameAndHelpAreAccessible(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $gauge = new AsyncGauge($r, 'temperature', 'CPU temperature in C', fn() => 65.0);
        $this->assertSame('temperature', $gauge->name());
        $this->assertSame('CPU temperature in C', $gauge->help());
    }
}
