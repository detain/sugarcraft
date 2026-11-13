<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\CellGeometry;
use SugarCraft\Image\CellSize;
use ReflectionClass;

/**
 * Direct coverage for the cell-geometry arithmetic (SI-43, finding
 * sugar-image-021).
 *
 * Before this file the class was tested only THROUGH transit: the Mosaic and
 * Render goldens would move if the precedence between a probed cell size and
 * the default flipped, but a golden moving tells you a picture changed, not
 * WHICH of the three laws below broke. These tests pin each law at its
 * source: default-vs-explicit precedence, the division DIRECTION (height ÷
 * width — a flipped ratio passes every 2.0-everywhere case because the
 * default equals its own reciprocal's conventional value), the half-up
 * rounding direction with its <1 floor, and the source-geometry law
 * height = width ÷ (sourceAspect · cellAspect) including its round-trip
 * inverse. Degenerate CellSize guards (either side <= 0) are pinned too:
 * callers pass through whatever a terminal probe answered, and a zero-sized
 * cell must fall back rather than divide.
 */
final class CellGeometryTest extends TestCase
{
    public function testTheDefaultCellAspectIsTheConventionalTwoToOne(): void
    {
        self::assertSame(2.0, CellGeometry::DEFAULT_CELL_ASPECT);
        self::assertSame(2.0, CellGeometry::cellAspect(null));
    }

    public function testAProbedCellSizeWinsOverTheDefaultAndDividesHeightByWidth(): void
    {
        // Direction matters: 25/10 is 2.5, and the reciprocal law would say
        // 0.4. A flipped division passes every square-ish probe because the
        // default 2.0 is its own reciprocal's conventional neighbor.
        self::assertSame(2.5, CellGeometry::cellAspect(new CellSize(10, 25)));
        self::assertSame(1.0, CellGeometry::cellAspect(new CellSize(9, 9)));
        // A genuinely landscape cell (wider than tall) must read below one.
        self::assertSame(0.5, CellGeometry::cellAspect(new CellSize(16, 8)));
    }

    public function testADegenerateProbedCellFallsBackToTheDefault(): void
    {
        self::assertSame(2.0, CellGeometry::cellAspect(new CellSize(0, 10)));
        self::assertSame(2.0, CellGeometry::cellAspect(new CellSize(10, 0)));
        self::assertSame(2.0, CellGeometry::cellAspect(new CellSize(-5, 10)));
        self::assertSame(2.0, CellGeometry::cellAspect(new CellSize(10, -1)));
    }

    public function testHeightCellsDerivesFromSourceAndCellAspectTogether(): void
    {
        // A square source (aspect 1.0) in 80 cells of 2:1 display is 40 rows
        // tall — the half-tall box the old square-cell math used to claim was
        // 80 rows. A 2:1 source halves it again to 20.
        self::assertSame(40, CellGeometry::heightCells(80, 1.0));
        self::assertSame(20, CellGeometry::heightCells(80, 2.0));
        // Same box, square cells: aspect 1.0 source is simply 80 rows.
        self::assertSame(80, CellGeometry::heightCells(80, 1.0, 1.0));
        // The cell aspect rides multiplicatively, including odd probes.
        self::assertSame(16, CellGeometry::heightCells(80, 2.0, 2.5));
    }

    public function testRoundingGoesHalfUpAndFloorsAtOneRow(): void
    {
        // PHP round() is half away from zero: 5/(1*2) = 2.5 lands on 3, and
        // 3/(1*2) = 1.5 lands on 2 — the direction is part of the contract
        // (a truncating cast would answer 2 and 1).
        self::assertSame(3, CellGeometry::heightCells(5, 1.0));
        self::assertSame(2, CellGeometry::heightCells(3, 1.0));
        // Anything that would round to zero still gets one row: the floor
        // every renderer has always applied to an auto height.
        self::assertSame(1, CellGeometry::heightCells(1, 1000.0));
        self::assertSame(1, CellGeometry::heightCells(1, 10.0, 10.0));
    }

    public function testWidthCellsIsTheSameLawReadFromTheOtherSide(): void
    {
        self::assertSame(160, CellGeometry::widthCells(80, 1.0));
        self::assertSame(80, CellGeometry::widthCells(20, 2.0));
        self::assertSame(1, CellGeometry::widthCells(1, 0.0001, 1.0));
        self::assertSame(2, CellGeometry::widthCells(1, 1.25, 1.2));
    }

    public function testTheTwoSidesRoundTripOnCleanNumbers(): void
    {
        foreach ([[80, 2.0, 2.0], [60, 1.0, 2.0], [40, 4.0 / 3.0, 2.0]] as [$width, $aspect, $cell]) {
            $rows = CellGeometry::heightCells($width, $aspect, $cell);
            self::assertSame(
                $width,
                CellGeometry::widthCells($rows, $aspect, $cell),
                "round trip at width {$width}, source {$aspect}, cell {$cell}"
            );
        }
    }

    public function testTheClassCarriesNoMutableState(): void
    {
        // Single source of truth only works if it is pure: final class, every
        // method static, no properties to fall out of sync between renderers.
        $class = new ReflectionClass(CellGeometry::class);
        self::assertTrue($class->isFinal());
        self::assertSame([], $class->getProperties());
        foreach ($class->getMethods() as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' must be static');
        }
    }
}
