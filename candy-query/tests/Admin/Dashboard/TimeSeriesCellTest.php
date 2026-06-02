<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Dashboard\TimeSeriesCell;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for the TimeSeriesCell timeline renderer.
 */
final class TimeSeriesCellTest extends TestCase
{
    public function testConstruction(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertTrue($cell->isEmpty());
        $this->assertSame(0, $cell->count());
    }

    public function testIngestSinglePoint(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $current = ['Bytes_received' => '1000'];
        $previous = ['Bytes_received' => '0'];
        $elapsed = 1.0;

        $result = $cell->ingest($current, $previous, $elapsed);

        $this->assertSame($cell, $result);
        $this->assertFalse($cell->isEmpty());
        $this->assertSame(1, $cell->count());
    }

    public function testIngestMultiplePoints(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Bytes_received' => '1000'], ['Bytes_received' => '0'], 1.0);
        $cell->ingest(['Bytes_received' => '2000'], ['Bytes_received' => '1000'], 1.0);
        $cell->ingest(['Bytes_received' => '3000'], ['Bytes_received' => '2000'], 1.0);

        $this->assertSame(3, $cell->count());
    }

    public function testIngestTrimsToWindowSize(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget, windowSize: 5);

        for ($i = 0; $i < 10; $i++) {
            $cell->ingest(
                ['Bytes_received' => (string) (($i + 1) * 1000)],
                ['Bytes_received' => (string) ($i * 1000)],
                1.0,
            );
        }

        $this->assertSame(5, $cell->count());
    }

    public function testNiceCeilingCalculation(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Bytes_received' => '4500'], ['Bytes_received' => '0'], 1.0);

        $this->assertSame(5000.0, $cell->ceiling());
        $this->assertSame(4500.0, $cell->maxSeen());
    }

    public function testNiceCeilingAt100(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Threads_connected'),
            format: '%d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Threads_connected' => '50'], ['Threads_connected' => '0'], 1.0);

        $this->assertSame(100.0, $cell->ceiling());
    }

    public function testNiceCeilingLargeValue(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Bytes_received' => '45000'], ['Bytes_received' => '0'], 1.0);

        $this->assertSame(50000.0, $cell->ceiling());
    }

    public function testReset(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Bytes_received' => '1000'], ['Bytes_received' => '0'], 1.0);
        $cell->ingest(['Bytes_received' => '2000'], ['Bytes_received' => '1000'], 1.0);

        $this->assertFalse($cell->isEmpty());
        $this->assertSame(2, $cell->count());

        $cell->reset();

        $this->assertTrue($cell->isEmpty());
        $this->assertSame(0, $cell->count());
        $this->assertSame(0.0, $cell->maxSeen());
        $this->assertSame(100.0, $cell->ceiling());
    }

    public function testIngestFromSnapshot(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $prev = new StatusSnapshot(['Bytes_received' => '0'], 1.0);
        $curr = new StatusSnapshot(['Bytes_received' => '1000'], 11.0);

        $cell->ingestFromSnapshot($curr, $prev);

        $this->assertFalse($cell->isEmpty());
        $this->assertSame(1, $cell->count());
    }

    public function testIngestFromSnapshotWithZeroElapsed(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $prev = new StatusSnapshot(['Bytes_received' => '0'], 1.0);
        $curr = new StatusSnapshot(['Bytes_received' => '1000'], 1.0);

        $cell->ingestFromSnapshot($curr, $prev);

        $this->assertTrue($cell->isEmpty());
    }

    public function testViewReturnsString(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            format: '%s/s',
            calc: new RatePerSecond('Bytes_received'),
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $output = $cell->view();

        $this->assertIsString($output);
    }

    public function testViewWithData(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $cell->ingest(['Bytes_received' => '1000'], ['Bytes_received' => '0'], 1.0);
        $cell->ingest(['Bytes_received' => '2000'], ['Bytes_received' => '1000'], 1.0);
        $cell->ingest(['Bytes_received' => '3000'], ['Bytes_received' => '2000'], 1.0);

        $output = $cell->view();

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testIngestRatePerSecond(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $current = ['Bytes_received' => '1100'];
        $previous = ['Bytes_received' => '1000'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed);

        $this->assertEqualsWithDelta(10.0, $cell->maxSeen(), 0.001);
    }

    public function testIngestZeroElapsedReturnsZero(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new TimeSeriesCell($widget);

        $current = ['Bytes_received' => '1100'];
        $previous = ['Bytes_received' => '1000'];
        $elapsed = 0.0;

        $cell->ingest($current, $previous, $elapsed);

        $this->assertTrue($cell->isEmpty());
    }

    public function testWidgetReturnsCorrectWidget(): void
    {
        $widget = new Widget(
            caption: 'Test Widget',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Test_key'),
            format: '%s',
            color: ['r' => 1, 'g' => 2, 'b' => 3],
        );

        $cell = new TimeSeriesCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertSame('Test Widget', $cell->widget()->caption);
    }
}
