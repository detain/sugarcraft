<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Layout\Tile\Dimension;
use SugarCraft\Dashboard\Layout\Tile\Size;
use SugarCraft\Dashboard\Layout\Tile\SizeHint;
use SugarCraft\Dashboard\Layout\Tile\SizeHinter;
use SugarCraft\Dashboard\Layout\Tile\Tile;
use SugarCraft\Dashboard\Layout\Tile\TileLayout;

/**
 * Shared recording cell so the probe can append tuples visible to the test.
 */
final class CallLog
{
    /** @var list<array{int, int}> */
    public array $calls = [];
}

/**
 * Axis contract pins for finding sugar-dashboard-041: a SizeHinter must be
 * asked with its own axes - the primary-axis budget belongs in the slot of
 * the orientation's main axis, never positionally into the width.
 */
final class TileSizeHintAxisTest extends TestCase
{
    /**
     * Fit content (weight 0) that records every sizeHint() budget pair.
     */
    private function probe(CallLog $log): Sizer
    {
        return new class ($log) implements Sizer, SizeHinter {
            public function __construct(private readonly CallLog $log) {}

            public function sizeHint(int $availWidth, int $availHeight): SizeHint
            {
                $this->log->calls[] = [$availWidth, $availHeight];

                return new SizeHint(new Dimension(1, 1), new Dimension(30, 6));
            }

            public function setSize(int $width, int $height): Sizer
            {
                return $this;
            }

            public function render(): string
            {
                return '';
            }
        };
    }

    public function testHorizontalFitPassesMainBudgetAsWidthAndHeightAsCross(): void
    {
        $log = new CallLog();
        $layout = TileLayout::horizontal('h')
            ->withTiles([new Tile('t', new Size(weight: 0), $this->probe($log))])
            ->setSize(100, 40);
        $layout->render();

        $this->assertNotEmpty($log->calls, 'the Fit hinter must be consulted');
        // Main-axis remaining width first, then the cross allocation.
        $this->assertSame([100, 40], $log->calls[0]);
    }

    public function testVerticalFitNeverSeesHeightAsWidth(): void
    {
        $log = new CallLog();
        $layout = TileLayout::vertical('v')
            ->withTiles([new Tile('t', new Size(weight: 0), $this->probe($log))])
            ->setSize(40, 100);
        $layout->render();

        $this->assertNotEmpty($log->calls, 'the Fit hinter must be consulted');
        // Pre-cure the vertical remaining HEIGHT was fed into the WIDTH
        // slot; the axis-correct call puts the width allocation first.
        $this->assertSame([40, 100], $log->calls[0]);
    }

    public function testMinSizeFitQueriesHinterWithAxisCorrectBudgets(): void
    {
        $log = new CallLog();
        $layout = TileLayout::vertical('v')
            ->withTiles([new Tile('t', new Size(weight: 0, minSizeFit: true), $this->probe($log))])
            ->setSize(40, 100);
        $layout->render();

        $this->assertNotEmpty($log->calls);
        // The minSizeFit pre-pass runs before phase 1, so its width slot
        // must already be the cross allocation (40) - never the height 100.
        $first = $log->calls[0];
        $this->assertSame(40, $first[0], 'vertical hinter width slot must be the cross budget');
        $this->assertSame(100, $first[1]);
    }

    public function testHintSizingUsesDesiredDimensionOfTheMainAxis(): void
    {
        $log = new CallLog();
        // desired 30x6: a vertical Fit row must measure against the real
        // width allocation; the height slot carries the main budget.
        $layout = TileLayout::vertical('v')
            ->withTiles([
                new Tile('t', new Size(weight: 0), $this->probe($log)),
                new Tile('rest', Size::flex(1.0)),
            ])
            ->setSize(40, 100);
        $out = $layout->render();

        $this->assertNotNull($out);
        $this->assertNotEmpty($log->calls);
        $this->assertSame([40, 100], $log->calls[0]);
    }
}
