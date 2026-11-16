<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Layout\Tile\Constraint;
use SugarCraft\Dashboard\Layout\Tile\Resolver;
use SugarCraft\Dashboard\Layout\Tile\Size;
use SugarCraft\Dashboard\Layout\Tile\Tile;
use SugarCraft\Dashboard\Layout\Tile\TileLayout;

/**
 * Pins for finding sugar-dashboard-034 (gapped tile layouts must paint real
 * spacer cells, not just subtract them), sugar-dashboard-132 (negative gap
 * clamps) and sugar-dashboard-217 (dead helper removal + honest maxSize).
 */
final class TileGapConstraintTest extends TestCase
{
    /**
     * Content that renders exactly one line of $width 'x' cells.
     */
    private function fillerWidth(): Sizer
    {
        return new class implements Sizer {
            private int $w = 0;

            public function setSize(int $width, int $height): Sizer
            {
                $clone = clone $this;
                $clone->w = $width;

                return $clone;
            }

            public function render(): string
            {
                return str_repeat('x', max(0, $this->w));
            }
        };
    }

    /**
     * Content that renders exactly $height lines.
     */
    private function fillerHeight(): Sizer
    {
        return new class implements Sizer {
            private int $h = 0;

            public function setSize(int $width, int $height): Sizer
            {
                $clone = clone $this;
                $clone->h = $height;

                return $clone;
            }

            public function render(): string
            {
                return implode("\n", array_fill(0, max(0, $this->h), 'r'));
            }
        };
    }

    public function testHorizontalGapPaintsRealSpacerCellsWithinBudget(): void
    {
        $filler = $this->fillerWidth();
        $layout = TileLayout::horizontal('h')
            ->withTiles([
                new Tile('t1', Size::fixed(9, 5), $filler),
                new Tile('t2', Size::fixed(9, 5), $filler),
            ])
            ->withGap(2)
            ->setSize(20, 5);

        $width = \SugarCraft\Core\Util\Width::string($layout->render());
        $this->assertSame(20, $width);
        $this->assertSame(str_repeat('x', 9) . '  ' . str_repeat('x', 9), $layout->render());
    }

    public function testVerticalGapPaintsRealSpacerRowsWithinBudget(): void
    {
        $filler = $this->fillerHeight();
        $layout = TileLayout::vertical('v')
            ->withTiles([
                new Tile('t1', Size::fixed(5, 3), $filler),
                new Tile('t2', Size::fixed(5, 3), $filler),
            ])
            ->withGap(2)
            ->setSize(5, 8);

        $lines = explode("\n", $layout->render());
        $this->assertCount(8, $lines);
        $this->assertSame(['r', 'r', 'r', '', '', 'r', 'r', 'r'], $lines);
    }

    public function testHorizontalGapZeroIsByteIdenticalToLegacyJoin(): void
    {
        $filler = $this->fillerWidth();
        $layout = TileLayout::horizontal('h')
            ->withTiles([
                new Tile('t1', Size::fixed(10, 5), $filler),
                new Tile('t2', Size::fixed(10, 5), $filler),
            ])
            ->setSize(20, 5);

        $this->assertSame(str_repeat('x', 20), $layout->render());
    }

    public function testNegativeGapClampsToZeroAndMatchesNoGapBytes(): void
    {
        $filler = $this->fillerWidth();
        $tiles = [
            new Tile('t1', Size::fixed(9, 5), $filler),
            new Tile('t2', Size::fixed(9, 5), $filler),
        ];

        $zero = TileLayout::horizontal('h')->withTiles($tiles)->withGap(0)->setSize(20, 5);
        $negative = TileLayout::horizontal('h')->withTiles($tiles)->withGap(-3)->setSize(20, 5);

        // The contract leg: withGap itself must clamp (reflection reaches the
        // private property; render-side max(0) is only the direct-ctor defense).
        $prop = new \ReflectionProperty(TileLayout::class, 'gap');
        $this->assertSame(0, $prop->getValue($negative));
        $this->assertSame($zero->render(), $negative->render());
        $this->assertSame(18, \SugarCraft\Core\Util\Width::string($negative->render()));
    }

    public function testDeadResolverHelpersAreGone(): void
    {
        foreach (['resolveTile', 'decideWidth', 'decideHeight'] as $name) {
            $this->assertFalse(
                method_exists(Resolver::class, $name),
                "Resolver::{$name} had zero callers and must stay deleted (finding sugar-dashboard-217)",
            );
        }
    }

    public function testMaxSizeDefaultsToNullAndHonorsHonestNoMax(): void
    {
        $this->assertNull(Constraint::fixed(7)->maxSize);
        // A zero maximum is nonsense for a visible tile; it now means no cap
        // instead of silently clamping the tile away.
        $this->assertNull(Constraint::fixed(7)->withMaxSize(0)->maxSize);
        $this->assertSame(5, Constraint::fixed(7)->withMaxSize(5)->maxSize);
    }
}
