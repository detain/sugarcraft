<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Rendition;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vcr\Cli\InspectCommand;

/**
 * The inspect hasher must treat two grids as different when they would render
 * differently. Before the truecolour/line-rendition work a cell hashed as
 * `char|fg|bg|attrs`, so a `38;2` repaint or an `ESC # 3`–`# 6` stamp produced
 * an identical SHA-1 to the untouched frame and the dedup pass collapsed them.
 * These tests pin that the packed RGB slots and the rendition are now in the
 * hash input.
 */
final class InspectHashSensitivityTest extends TestCase
{
    private function hash(Snapshot $snapshot): string
    {
        $method = new ReflectionMethod(InspectCommand::class, 'hashGrid');
        $method->setAccessible(true);

        return $method->invoke(new InspectCommand(), $snapshot);
    }

    private function snapshot(callable $paint): Snapshot
    {
        $grid = new CellGrid(4, 2);
        $grid = $paint($grid);

        return new Snapshot($grid, new Cursor(), 0.0);
    }

    public function testBaselineGridWithPlainPaletteCellsIsStable(): void
    {
        $paint = static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', fg: 1, bg: 2));

        $this->assertSame($this->hash($this->snapshot($paint)), $this->hash($this->snapshot($paint)));
    }

    public function testTruecolourForegroundChangesTheHash(): void
    {
        $plain = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', fg: 1, bg: 2)));
        $truecolour = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(
            0,
            0,
            new Cell(char: 'X', fg: 1, bg: 2, fgTruecolor: 0x123456),
        ));

        $this->assertNotSame(
            $this->hash($plain),
            $this->hash($truecolour),
            'a packed truecolour foreground must survive the same palette numbers and still hash apart',
        );
    }

    public function testDifferentTruecolourValuesChangeTheHash(): void
    {
        $red = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', fgTruecolor: 0xFF0000)));
        $blue = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', fgTruecolor: 0x0000FF)));

        $this->assertNotSame($this->hash($red), $this->hash($blue));
    }

    public function testTruecolourBackgroundChangesTheHashIndependentlyOfForeground(): void
    {
        // Only the background slot differs between the two cells; the foreground
        // palette value is identical, so a hash that ignores bgTruecolor collapses
        // these and this assertion fails.
        $plain = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', fg: 1, bg: 2)));
        $truecolourBg = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(
            0,
            0,
            new Cell(char: 'X', fg: 1, bg: 2, bgTruecolor: 0x654321),
        ));

        $this->assertNotSame(
            $this->hash($plain),
            $this->hash($truecolourBg),
            'a packed truecolour background must hash apart from the same palette numbers',
        );

        // Two distinct background values must also separate from each other,
        // mirroring the foreground test above.
        $red = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', bgTruecolor: 0xFF0000)));
        $green = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', bgTruecolor: 0x00FF00)));
        $this->assertNotSame($this->hash($red), $this->hash($green));
    }

    public function testLineRenditionChangesTheHash(): void
    {
        $single = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', rendition: Rendition::None)));
        $double = $this->snapshot(static fn (CellGrid $g): CellGrid => $g->set(0, 0, new Cell(char: 'X', rendition: Rendition::DoubleWidth)));

        $this->assertNotSame(
            $this->hash($single),
            $this->hash($double),
            'a DECDWL stamp must not hash identically to a single-width line',
        );
    }
}
