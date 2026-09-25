<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Query\Admin\Calc\MakeTuple;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Dashboard\MultiSeriesCell;
use SugarCraft\Query\Admin\Dashboard\Widget;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for the multi-series braille timeline cell.
 */
final class MultiSeriesCellTest extends TestCase
{
    private function scalarWidget(): Widget
    {
        return new Widget(
            caption: 'Bytes In',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: new RatePerSecond('Bytes_received'),
            format: '%s/s',
            color: ['r' => 60, 'g' => 178, 'b' => 191],
        );
    }

    private function tupleWidget(): Widget
    {
        return new Widget(
            caption: 'SQL Statements',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: (new MakeTuple(','))
                ->addRate('Com_select')
                ->addRate('Com_insert'),
            format: '%s/s',
            color: ['r' => 255, 'g' => 215, 'b' => 0],
        );
    }

    public function testConstructionStartsEmpty(): void
    {
        $cell = new MultiSeriesCell($this->scalarWidget());

        $this->assertTrue($cell->isEmpty());
        $this->assertSame(0, $cell->count());
        $this->assertSame([], $cell->seriesNames());
    }

    public function testScalarIngestCreatesOneSeriesNamedByCaption(): void
    {
        $cell = new MultiSeriesCell($this->scalarWidget());

        $cell->ingest(['Bytes_received' => '1000'], ['Bytes_received' => '0'], 1.0);

        $this->assertSame(['Bytes In'], $cell->seriesNames());
        $this->assertSame(1, $cell->count());
        $this->assertSame(1000.0, $cell->latest('Bytes In'));
    }

    public function testTupleIngestCreatesOneSeriesPerKeyInOrder(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget());

        $cell->ingest(
            ['Com_select' => '100', 'Com_insert' => '40'],
            ['Com_select' => '0', 'Com_insert' => '0'],
            1.0,
        );

        $this->assertSame(['Com_select', 'Com_insert'], $cell->seriesNames());
        $this->assertSame(2, $cell->count());
        $this->assertSame(100.0, $cell->latest('Com_select'));
        $this->assertSame(40.0, $cell->latest('Com_insert'));
    }

    public function testZeroIsPlottableIdleShowsFloorLine(): void
    {
        $cell = new MultiSeriesCell($this->scalarWidget());

        // No traffic: delta 0 must still add samples (flat floor trace,
        // like Workbench) instead of being dropped as "no data".
        $cell->ingest(['Bytes_received' => '5'], ['Bytes_received' => '5'], 1.0);
        $cell->ingest(['Bytes_received' => '5'], ['Bytes_received' => '5'], 1.0);

        $this->assertSame(2, $cell->count());
        $this->assertSame(0.0, $cell->latest('Bytes In'));

        $rows = explode("\n", $cell->view());
        // Height 6 → 6 braille rows; the flat line rides the last row.
        $this->assertCount(6, $rows);
        $this->assertNotSame('', trim($rows[5]));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('', trim($rows[$i]), "row $i must stay blank for a zero trace");
        }
    }

    public function testNegativeAndNonFiniteSamplesRejected(): void
    {
        $calc = new class {
            /** @return array<string,float> */
            public function compute(array $current, array $previous, float $elapsed): array
            {
                return ['bad' => -5.0, 'nan' => NAN, 'good' => 3.0];
            }
        };
        $widget = new Widget(
            caption: 'Edge',
            kind: WidgetRegistry::KIND_TIMELINE,
            calc: $calc,
            format: '%s/s',
            color: ['r' => 1, 'g' => 2, 'b' => 3],
        );

        $cell = new MultiSeriesCell($widget);
        $cell->ingest([], [], 1.0);

        $this->assertSame(['good'], $cell->seriesNames());
        $this->assertSame(1, $cell->count());
    }

    public function testWindowTrimsPerSeries(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget(), windowSize: 5);

        for ($i = 1; $i <= 10; $i++) {
            $cell->ingest(
                ['Com_select' => (string) ($i * 100), 'Com_insert' => (string) ($i * 10)],
                ['Com_select' => (string) (($i - 1) * 100), 'Com_insert' => (string) (($i - 1) * 10)],
                1.0,
            );
        }

        $this->assertSame(10, $cell->count()); // 5 + 5
        $this->assertSame(100.0, $cell->latest('Com_select')); // constant 100/s rate
    }

    public function testNiceCeilingAcrossSeries(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget());

        $cell->ingest(
            ['Com_select' => '4500', 'Com_insert' => '10'],
            ['Com_select' => '0', 'Com_insert' => '0'],
            1.0,
        );

        $this->assertSame(5000.0, $cell->ceiling());
        $this->assertSame(4500.0, $cell->maxSeen());
    }

    public function testRisingSeriesPaintsTopRowWhileFlatOneStaysOnFloor(): void
    {
        // Differential pin: series A climbs to 900 (nice ceiling rounds to
        // 1000, so the peak normalises to 0.9 and touches the top braille
        // row) while B idles at zero, tracing only the floor.
        $rising = (new MultiSeriesCell($this->tupleWidget()))
            ->ingest(['Com_select' => '0', 'Com_insert' => '0'], ['Com_select' => '0', 'Com_insert' => '0'], 1.0)
            ->ingest(['Com_select' => '900', 'Com_insert' => '0'], ['Com_select' => '0', 'Com_insert' => '0'], 1.0);

        $rows = explode("\n", $rising->view(40));
        $this->assertNotSame('', trim($rows[0]), 'rising series must reach the top row');
        $this->assertNotSame('', trim($rows[count($rows) - 2]), 'floor line sits above the legend row');

        // A-only flat-zero control leaves every row above the floor blank.
        $flat = (new MultiSeriesCell($this->scalarWidget()))
            ->ingest(['Bytes_received' => '0'], ['Bytes_received' => '0'], 1.0);
        $flatRows = explode("\n", $flat->view(40));
        $this->assertSame('', trim($flatRows[0]));
    }

    public function testLegendOnlyForMultiSeriesWideCanvas(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget());
        $cell->ingest(['Com_select' => '10', 'Com_insert' => '5'], ['Com_select' => '0', 'Com_insert' => '0'], 1.0);

        $wide = explode("\n", $cell->view(40));
        $this->assertCount(7, $wide, 'legend line rides below the 6 graph rows');
        $this->assertStringContainsString('Com_select', end($wide));
        $this->assertStringContainsString('Com_insert', end($wide));

        $narrow = explode("\n", $cell->view(20));
        $this->assertCount(6, $narrow, 'no legend below the width floor');

        $single = new MultiSeriesCell($this->scalarWidget());
        $single->ingest(['Bytes_received' => '10'], ['Bytes_received' => '0'], 1.0);
        $this->assertCount(6, explode("\n", $single->view(60)), 'single series never gets a legend');
    }

    /**
     * Width sweep: whatever the clamp, no rendered row may exceed the
     * allotted cells — the 80-col terminal must degrade, never overflow.
     */
    public function testRenderedWidthNeverExceedsAllotted(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget());
        for ($i = 1; $i <= 30; $i++) {
            $cell->ingest(
                ['Com_select' => (string) ($i * 7), 'Com_insert' => (string) $i],
                ['Com_select' => (string) (($i - 1) * 7), 'Com_insert' => (string) ($i - 1)],
                1.0,
            );
        }

        foreach ([8, 12, 16, 20, 24, 27, 28, 33, 40, 60, 80, 120, 200] as $allotted) {
            $rendered = $cell->view($allotted);
            foreach (explode("\n", $rendered) as $row) {
                $this->assertLessThanOrEqual(
                    $allotted,
                    Width::of($row),
                    "row overflows at width $allotted: " . Width::of($row),
                );
            }
            // Graph always carries its configured height in rows (+1 legend).
            $rows = explode("\n", $rendered);
            $this->assertContainsRowCount($allotted, count($rows));
        }
    }

    private function assertContainsRowCount(int $allotted, int $actual): void
    {
        $expected = $allotted >= 28 ? 7 : 6; // legend only at/above the floor
        $this->assertSame($expected, $actual, "row budget at width $allotted");
    }

    public function testResetClearsWindowsAndScale(): void
    {
        $cell = new MultiSeriesCell($this->tupleWidget());
        $cell->ingest(['Com_select' => '4500', 'Com_insert' => '10'], ['Com_select' => '0', 'Com_insert' => '0'], 1.0);

        $cell->reset();

        $this->assertTrue($cell->isEmpty());
        $this->assertSame(0, $cell->count());
        $this->assertSame(0.0, $cell->maxSeen());
        $this->assertSame(100.0, $cell->ceiling());
    }

    public function testIngestFromSnapshotMirrorsPlainIngest(): void
    {
        $cell = new MultiSeriesCell($this->scalarWidget());
        $prev = new StatusSnapshot(['Bytes_received' => '0'], 1.0);
        $curr = new StatusSnapshot(['Bytes_received' => '2000'], 11.0);

        $cell->ingestFromSnapshot($curr, $prev);

        $this->assertSame(200.0, $cell->latest('Bytes In')); // 2000 bytes / 10 s
    }

    public function testEmptyViewStillDrawsBlankFrame(): void
    {
        $cell = new MultiSeriesCell($this->scalarWidget(), height: 4);

        $rows = explode("\n", $cell->view());

        $this->assertCount(4, $rows);
        $this->assertSame('', trim($rows[0]));
    }

    public function testDownsampleWhenWindowWiderThanCanvas(): void
    {
        // 120 samples into a 20-cell (40 px) canvas must thin, not clip:
        // the newest sample still rides the right edge and the oldest the
        // left edge, so both borders carry ink.
        $cell = new MultiSeriesCell($this->scalarWidget(), windowSize: 120);
        for ($i = 1; $i <= 120; $i++) {
            $cell->ingest(
                ['Bytes_received' => (string) ($i % 2 === 0 ? 5000 : 0)],
                ['Bytes_received' => '0'],
                1.0,
            );
        }

        $rows = explode("\n", $cell->view(20));
        $this->assertSame(120, $cell->count());

        // At least one graph row carries ink at BOTH horizontal extremes of
        // the 20-cell frame: the oldest sample survives at x=0, the newest
        // at the right edge — i.e. the trace was thinned, not right-clipped.
        $spanning = false;
        foreach ($rows as $row) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $row) ?? '';
            if ($plain === '') {
                continue;
            }
            $cells = str_pad($plain, 20, ' ', STR_PAD_RIGHT);
            if (mb_substr($cells, 0, 1) !== ' ' && mb_substr(rtrim($plain), -1, 1) !== ' ') {
                $spanning = true;
            }
        }
        $this->assertTrue($spanning, 'thinned trace must span the full canvas width');
    }
}
