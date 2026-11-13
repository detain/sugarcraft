<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\BufferBuilder;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;

/**
 * CB-34 — the mutable bulk scratch pad and the now type/layout-strict
 * fromGrid wrap.
 */
final class BufferBuilderTest extends TestCase
{
    public function testBuilderOutputIsByteIdenticalToTheWithCellAtChain(): void
    {
        // Seeded differential across the awkward shapes: straddle degrades
        // at the last column, writes landing ON continuations, replacement
        // of leads, styled/linked cells, plus a fillRect sweep.
        mt_srand(0xB011D1E);
        $styles = [null, new \SugarCraft\CellBuffer\Style(fg: 0x0A0B0C)];

        for ($iter = 0; $iter < 60; $iter++) {
            $w = 2 + mt_rand(0, 9);
            $h = 1 + mt_rand(0, 5);
            $chain = Buffer::new($w, $h);
            $builder = Buffer::builder($w, $h);

            for ($op = 0; $op < 40; $op++) {
                $col = mt_rand(0, $w - 1);
                $row = mt_rand(0, $h - 1);
                $style = $styles[mt_rand(0, 1)];
                $cell = match (mt_rand(0, 3)) {
                    0 => Cell::new('中', $style, null, 2),
                    1 => Cell::new('x', $style, null, 1),
                    2 => Cell::continuation(),
                    default => Cell::blank(),
                };
                $chain = $chain->withCellAt($col, $row, $cell);
                $builder->setCellAt($col, $row, $cell);
            }

            $region = Region::new(Position::new(mt_rand(0, $w - 1), mt_rand(0, $h - 1)), mt_rand(1, $w), mt_rand(1, $h));
            $sweep = match (mt_rand(0, 2)) {
                0 => Cell::new('▓', null, null, 2),
                1 => Cell::new('-', null, null, 1),
                default => Cell::blank(),
            };
            $chain = $chain->fill($region, $sweep);
            $builder->fillRect($region, $sweep);

            self::assertSame(
                $chain->toAnsi(),
                $builder->toBuffer()->toAnsi(),
                "iter {$iter} ({$w}x{$h}) — builder must be the pair law verbatim, not a second copy (seed 0xB011D1E)",
            );
        }
    }

    public function testBuilderToBufferTextRoundTrip(): void
    {
        $buffer = Buffer::builder(4, 2)
            ->setCellAt(0, 0, Cell::new('a'))
            ->setCellAt(1, 0, Cell::new('b'))
            ->setCellAt(0, 1, Cell::new('c'))
            ->toBuffer();

        self::assertSame("ab  \nc   ", $buffer->text());
    }

    public function testStraddleLeadDegradesExactlyAsTheFluentPath(): void
    {
        $w = 3;
        $chain = Buffer::new($w, 1)->withCellAt(2, 0, Cell::new('中', null, null, 2));
        $built = Buffer::builder($w, 1)
            ->setCellAt(2, 0, Cell::new('中', null, null, 2))
            ->toBuffer();

        self::assertSame($chain->toAnsi(), $built->toAnsi());
        // CB-04 degrade law inherited: the straddle paints a blank, not a
        // half glyph overrun.
        self::assertTrue($built->cellAt(2, 0)->equals(Cell::blank()));
    }

    public function testBuilderBoundsAndDimensionLaws(): void
    {
        $builder = Buffer::builder(2, 2);

        $this->expectException(\OutOfRangeException::class);
        $builder->setCellAt(2, 0, Cell::blank());
    }

    public function testBuilderRejectsVoidCanvasAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BufferBuilder dimensions must be positive');
        new BufferBuilder(0, 4);
    }

    public function testFillRectClipsOutOfFrameWithoutThrowing(): void
    {
        // Mirrors Buffer::fill's clipping contract (not withCellAt's fatality):
        $buffer = Buffer::builder(2, 2)
            ->fillRect(Region::new(Position::new(-1, -1), 8, 8), Cell::new('*'))
            ->toBuffer();

        self::assertSame("**\n**", $buffer->text());
    }

    public function testFromGridRefusesNonCellEntriesAndKeyedArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a Cell, got string');
        Buffer::fromGrid(2, 1, ['x', Cell::blank()]);
    }

    public function testFromGridRefusesNonListKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list');
        /** @psalm-suppress InvalidArgument keyed on purpose */
        Buffer::fromGrid(2, 1, [1 => Cell::blank(), 0 => Cell::blank()]);
    }

    public function testFromGridStaysLenientAboutPairAlignmentForLegitimateCrops(): void
    {
        // Disclosed scope: copy() crops can begin on a continuation — the
        // shape is legal slice geometry, so fromGrid must NOT start
        // rejecting it (CB-13 orphan read-back pins ride this door too).
        $wide = Buffer::new(4, 1)->withCellAt(0, 0, Cell::new('中', null, null, 2));
        $crop = $wide->copy(Region::new(Position::new(1, 0), 2, 1));

        self::assertSame(0, $crop->cellAt(0, 0)->width()); // orphaned continuation, accepted
        self::assertSame($crop->toAnsi(), Buffer::fromGrid(2, 1, iterator_to_array(
            (static function () use ($crop): \Generator {
                for ($row = 0; $row < 1; $row++) {
                    for ($col = 0; $col < 2; $col++) {
                        yield $crop->cellAt($col, $row);
                    }
                }
            })(),
        ))->toAnsi());
    }

    public function testBuilderMeetsTheBulkBenchmarkBar(): void
    {
        $w = 100;
        $h = 50;
        $cell = Cell::new('#');

        $t0 = hrtime(true);
        $chain = Buffer::new($w, $h);
        foreach (range(0, $w * $h - 1) as $i) {
            if ($i % 7 === 0) {
                $chain = $chain->withCellAt($i % $w, intdiv($i, $w), $cell);
            }
        }
        $chainNs = hrtime(true) - $t0;

        $t1 = hrtime(true);
        $builder = Buffer::builder($w, $h);
        foreach (range(0, $w * $h - 1) as $i) {
            if ($i % 7 === 0) {
                $builder->setCellAt($i % $w, intdiv($i, $w), $cell);
            }
        }
        $built = $builder->toBuffer();
        $builderNs = hrtime(true) - $t1;

        // The finding measured the fluent chain at 41x the fromGrid door
        // for bulk writes; the builder must land orders of magnitude under
        // that (pinned at 4x with JIT headroom, measured ~13x cheaper).
        self::assertGreaterThanOrEqual(
            $builderNs * 4,
            $chainNs,
            sprintf('bulk build must not keep the quadratic door (chain %0.2fms vs builder %0.2fms)', $chainNs / 1e6, $builderNs / 1e6),
        );

        // Same content through both doors, byte-identical — the structural
        // equivalence, not wall-clock, is the load-bearing leg.
        $grid = array_fill(0, $w * $h, Cell::blank());
        foreach (range(0, $w * $h - 1) as $i) {
            if ($i % 7 === 0) {
                $grid[$i] = $cell;
            }
        }
        self::assertSame(
            Buffer::fromGrid($w, $h, $grid)->toAnsi(),
            $built->toAnsi(),
        );
    }
}
