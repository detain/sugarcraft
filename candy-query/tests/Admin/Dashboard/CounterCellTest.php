<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Dashboard\CounterCell;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for CounterCell.
 */
final class CounterCellTest extends TestCase
{
    public function testConstruction(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertFalse($cell->hasValue());
    }

    public function testIngestSingleValue(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $current = ['Com_select' => '1100'];
        $previous = ['Com_select' => '1000'];
        $elapsed = 10.0;

        $result = $cell->ingest($current, $previous, $elapsed);

        $this->assertSame($cell, $result);
        $this->assertTrue($cell->hasValue());
        $this->assertEqualsWithDelta(10.0, $cell->lastValue(), 0.001);
    }

    public function testIngestMultipleValues(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $cell->ingest(['Com_select' => '1100'], ['Com_select' => '1000'], 10.0);
        $this->assertEqualsWithDelta(10.0, $cell->lastValue(), 0.001);

        $cell->ingest(['Com_select' => '1200'], ['Com_select' => '1100'], 10.0);
        $this->assertEqualsWithDelta(10.0, $cell->lastValue(), 0.001);
    }

    public function testRawFormatted(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%.1f/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget, decimals: 1);

        $cell->ingest(['Com_select' => '1100'], ['Com_select' => '1000'], 10.0);

        $this->assertSame('10.0/s', $cell->rawFormatted());
    }

    public function testScaledFormattedSmall(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget, decimals: 1);

        $cell->ingest(['Com_select' => '500'], ['Com_select' => '0'], 1.0);

        $this->assertSame('500', $cell->scaledFormatted());
    }

    public function testScaledFormattedKilo(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget, decimals: 1);

        $cell->ingest(['Bytes_received' => '1536000'], ['Bytes_received' => '0'], 1.0);

        $this->assertSame('1.5M', $cell->scaledFormatted());
    }

    public function testScaledFormattedWithUnit(): void
    {
        $widget = new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s B/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget, decimals: 1);

        $cell->ingest(['Bytes_received' => '1024000'], ['Bytes_received' => '0'], 1.0);

        $this->assertSame('1000K B/s', $cell->scaledFormatted('B/s'));
    }

    public function testIngestFromSnapshot(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $prev = new StatusSnapshot(['Com_select' => '1000'], 1.0);
        $curr = new StatusSnapshot(['Com_select' => '1100'], 11.0);

        $cell->ingestFromSnapshot($curr, $prev);

        $this->assertTrue($cell->hasValue());
        $this->assertEqualsWithDelta(10.0, $cell->lastValue(), 0.001);
    }

    public function testIngestFromSnapshotWithZeroElapsed(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $prev = new StatusSnapshot(['Com_select' => '1000'], 1.0);
        $curr = new StatusSnapshot(['Com_select' => '1100'], 1.0);

        $cell->ingestFromSnapshot($curr, $prev);

        $this->assertFalse($cell->hasValue());
    }

    public function testReset(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $cell->ingest(['Com_select' => '1100'], ['Com_select' => '1000'], 10.0);
        $this->assertTrue($cell->hasValue());

        $cell->reset();

        $this->assertFalse($cell->hasValue());
        $this->assertSame(0.0, $cell->lastValue());
    }

    public function testViewReturnsString(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $output = $cell->view();
        $this->assertIsString($output);
        $this->assertSame('', $output);
    }

    public function testViewWithValue(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget, decimals: 1);

        $cell->ingest(['Com_select' => '1100'], ['Com_select' => '1000'], 10.0);

        $output = $cell->view();
        $this->assertIsString($output);
        $this->assertSame('10', $output);
    }

    public function testWidgetReturnsCorrectWidget(): void
    {
        $widget = new Widget(
            caption: 'Test Counter',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Test_key'),
            format: '%s',
            color: ['r' => 1, 'g' => 2, 'b' => 3],
        );

        $cell = new CounterCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertSame('Test Counter', $cell->widget()->caption);
    }

    public function testIngestZeroElapsedNoChange(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $cell->ingest(['Com_select' => '1100'], ['Com_select' => '1000'], 0.0);

        $this->assertFalse($cell->hasValue());
    }

    public function testIngestNegativeValueNoChange(): void
    {
        $widget = new Widget(
            caption: 'SELECT',
            kind: WidgetRegistry::KIND_COUNTER,
            calc: new RatePerSecond('Com_select'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new CounterCell($widget);

        $cell->ingest(['Com_select' => '1000'], ['Com_select' => '1100'], 10.0);

        $this->assertTrue($cell->hasValue());
        $this->assertSame(0.0, $cell->lastValue());
    }
}
