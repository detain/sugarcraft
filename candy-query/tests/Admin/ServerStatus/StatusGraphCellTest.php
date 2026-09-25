<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerStatus\StatusGraphCell;

/**
 * Geometry + color-band pins for the parity graphs: the stacked pre-sum
 * trick depends on AreaChart's later-series-overwrites-earlier behavior, so
 * the visible band order is asserted at the byte level, not by eye.
 */
final class StatusGraphCellTest extends TestCase
{
    private const string TOP_RGB = '38;2;243;139;168'; // #f38ba8

    private const string BOTTOM_RGB = '38;2;166;227;161'; // #a6e3a1

    public function testStackedPaintsBottomBandInFirstColorAndTopBandInSecond(): void
    {
        $cell = new StatusGraphCell('Traffic', ['in', 'out'], ['#a6e3a1', '#f38ba8'], true);
        // Two equal-rate samples: total sits on the 100 axis (full height),
        // the bottom half repaints in the first color.
        $cell->ingest(['in' => 50.0, 'out' => 50.0]);
        $cell->ingest(['in' => 50.0, 'out' => 50.0]);

        $lines = explode("\n", $cell->view(10, 6));

        self::assertStringContainsString(self::TOP_RGB, $lines[0], 'top row belongs to the upper band');
        self::assertStringContainsString(self::BOTTOM_RGB, $lines[count($lines) - 1], 'bottom row belongs to the lower band');
    }

    public function testFilledSingleSeriesPaintsWholeColumn(): void
    {
        $cell = new StatusGraphCell('Connections', ['threads'], ['#89b4fa'], false);
        $cell->ingest(['threads' => 100.0]); // == the 1-2-2.5-5-10 axis for a 100 peak
        $cell->ingest(['threads' => 100.0]);

        $lines = explode("\n", $cell->view(10, 6));
        $rgb = '38;2;137;180;250';

        self::assertStringContainsString($rgb, $lines[0]);
        self::assertStringContainsString($rgb, $lines[count($lines) - 1]);
    }

    public function testEmptyWindowRendersBlankOfExactShape(): void
    {
        $cell = new StatusGraphCell('Connections', ['threads'], ['#89b4fa'], false);

        $lines = explode("\n", $cell->view(12, 5));

        self::assertCount(5, $lines);
        foreach ($lines as $line) {
            self::assertSame(12, mb_strwidth($line));
        }
    }

    public function testMissingKeysIngestAsZeroToKeepOnePointPerPoll(): void
    {
        $cell = new StatusGraphCell('Traffic', ['in', 'out'], ['#a6e3a1', '#f38ba8'], true);
        $cell->ingest(['in' => 5.0]);
        $cell->ingest(['in' => 7.0, 'out' => 2.0]);

        self::assertSame([0.0, 2.0], $cell->series('out'));
        self::assertSame(['in' => 7.0, 'out' => 2.0], $cell->latest());
    }

    public function testWindowTrimsOldestFirst(): void
    {
        $cell = new StatusGraphCell('Connections', ['threads'], ['#89b4fa'], false, 3);

        foreach ([1.0, 2.0, 3.0, 4.0] as $v) {
            $cell->ingest(['threads' => $v]);
        }

        self::assertSame([2.0, 3.0, 4.0], $cell->series('threads'));
    }
}
