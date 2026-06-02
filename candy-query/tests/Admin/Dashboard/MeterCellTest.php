<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Dashboard\MeterCell;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for MeterCell.
 */
final class MeterCellTest extends TestCase
{
    public function testConstruction(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertFalse($cell->hasValue());
    }

    public function testIngestBasicRatio(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '50'];
        $previous = ['Threads_connected' => '40'];
        $serverVars = ['max_connections' => '100'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertTrue($cell->hasValue());
        $this->assertEqualsWithDelta(0.5, $cell->ratio(), 0.001);
        $this->assertSame(50, $cell->percentage());
    }

    public function testIngestAtMaxConnections(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '100'];
        $previous = ['Threads_connected' => '90'];
        $serverVars = ['max_connections' => '100'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertEqualsWithDelta(1.0, $cell->ratio(), 0.001);
        $this->assertSame(100, $cell->percentage());
    }

    public function testRatioClampedToOne(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '150'];
        $previous = ['Threads_connected' => '140'];
        $serverVars = ['max_connections' => '100'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertEqualsWithDelta(1.0, $cell->ratio(), 0.001);
    }

    public function testIngestFromSnapshot(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $prev = new StatusSnapshot(['Threads_connected' => '40'], 1.0);
        $curr = new StatusSnapshot(['Threads_connected' => '50'], 11.0);
        $serverVars = ['max_connections' => '100'];

        $cell->ingestFromSnapshot($curr, $prev, $serverVars);

        $this->assertTrue($cell->hasValue());
        $this->assertEqualsWithDelta(0.5, $cell->ratio(), 0.001);
    }

    public function testIngestFromSnapshotWithZeroElapsed(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $prev = new StatusSnapshot(['Threads_connected' => '40'], 1.0);
        $curr = new StatusSnapshot(['Threads_connected' => '50'], 1.0);
        $serverVars = ['max_connections' => '100'];

        $cell->ingestFromSnapshot($curr, $prev, $serverVars);

        $this->assertFalse($cell->hasValue());
    }

    public function testWithRatio(): void
    {
        $widget = new Widget(
            caption: 'Buffer Pool',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Innodb_buffer_pool_pages_total'),
            format: '%.0f%%',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $result = $cell->withRatio(0.75);

        $this->assertNotSame($cell, $result);
        $this->assertTrue($result->hasValue());
        $this->assertEqualsWithDelta(0.75, $result->ratio(), 0.001);
        $this->assertSame(75, $result->percentage());
    }

    public function testWithRatioClamped(): void
    {
        $widget = new Widget(
            caption: 'Test',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Test_key'),
            format: '%.0f%%',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $result = $cell->withRatio(1.5);
        $this->assertEqualsWithDelta(1.0, $result->ratio(), 0.001);

        $result2 = $cell->withRatio(-0.5);
        $this->assertEqualsWithDelta(0.0, $result2->ratio(), 0.001);
    }

    public function testReset(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '50'];
        $serverVars = ['max_connections' => '100'];
        $cell->ingest($current, ['Threads_connected' => '40'], 10.0, $serverVars);

        $this->assertTrue($cell->hasValue());

        $cell->reset();

        $this->assertFalse($cell->hasValue());
        $this->assertSame(0.0, $cell->ratio());
    }

    public function testViewRoundReturnsString(): void
    {
        $widget = new Widget(
            caption: 'Buffer Pool',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Innodb_buffer_pool_pages_total'),
            format: '%.0f%%',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $output = $cell->viewRound();
        $this->assertIsString($output);
    }

    public function testViewLevelReturnsString(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $output = $cell->viewLevel();
        $this->assertIsString($output);
    }

    public function testViewMeterReturnsString(): void
    {
        $widget = new Widget(
            caption: 'Test',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Test_key'),
            format: '%d',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $output = $cell->viewMeter();
        $this->assertIsString($output);
    }

    public function testViewWithRoundKind(): void
    {
        $widget = new Widget(
            caption: 'Buffer Pool',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Innodb_buffer_pool_pages_total'),
            format: '%.0f%%',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $output = $cell->view();
        $this->assertIsString($output);
    }

    public function testViewWithLevelKind(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $output = $cell->view();
        $this->assertIsString($output);
    }

    public function testViewWithValueRound(): void
    {
        $widget = new Widget(
            caption: 'Buffer Pool',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Innodb_buffer_pool_pages_total'),
            format: '%.0f%%',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );

        $cell = new MeterCell($widget);

        $cell->ingest(
            ['Innodb_buffer_pool_pages_total' => '80'],
            ['Innodb_buffer_pool_pages_total' => '70'],
            10.0,
            ['Innodb_buffer_pool_pages_total' => '100'],
        );

        $output = $cell->view();
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testViewWithValueLevel(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $cell->ingest(
            ['Threads_connected' => '50'],
            ['Threads_connected' => '40'],
            10.0,
            ['max_connections' => '100'],
        );

        $output = $cell->view();
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testWidgetReturnsCorrectWidget(): void
    {
        $widget = new Widget(
            caption: 'Test Meter',
            kind: WidgetRegistry::KIND_ROUND,
            calc: new StatusVar('Test_key'),
            format: '%s',
            color: ['r' => 1, 'g' => 2, 'b' => 3],
        );

        $cell = new MeterCell($widget);

        $this->assertSame($widget, $cell->widget());
        $this->assertSame('Test Meter', $cell->widget()->caption);
    }

    public function testMaxOverride(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget, maxOverride: 200.0);

        $current = ['Threads_connected' => '100'];
        $previous = ['Threads_connected' => '90'];
        $serverVars = ['max_connections' => '100'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertEqualsWithDelta(0.5, $cell->ratio(), 0.001);
    }

    public function testResolveMaxFromServerVars(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '75'];
        $previous = ['Threads_connected' => '70'];
        $serverVars = ['max_connections' => '150'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertEqualsWithDelta(0.5, $cell->ratio(), 0.001);
    }

    public function testResolveMaxFromCurrentVars(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '75', 'max_connections' => '150'];
        $previous = ['Threads_connected' => '70'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, null);

        $this->assertEqualsWithDelta(0.5, $cell->ratio(), 0.001);
    }

    public function testZeroMaxGivesZeroRatio(): void
    {
        $widget = new Widget(
            caption: 'Connections',
            kind: WidgetRegistry::KIND_LEVEL,
            calc: new StatusVar('Threads_connected'),
            format: '%d / %d',
            color: ['r' => 124, 'g' => 193, 'b' => 80],
            serverVarsKeys: ['max' => 'max_connections'],
        );

        $cell = new MeterCell($widget);

        $current = ['Threads_connected' => '50'];
        $previous = ['Threads_connected' => '40'];
        $serverVars = ['max_connections' => '0'];
        $elapsed = 10.0;

        $cell->ingest($current, $previous, $elapsed, $serverVars);

        $this->assertSame(0.0, $cell->ratio());
    }
}
