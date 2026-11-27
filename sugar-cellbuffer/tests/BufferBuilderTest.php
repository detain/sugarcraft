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

        // The quadratic door is a whole-grid copy on every write: n writes
        // into an n-cell canvas cost O(n) each, O(n²) in all. That copy is an
        // allocation of at least one zval (16 bytes) per cell, so the guard
        // measures the bytes each write allocates, not wall-clock time. A
        // wall-clock ratio collapsed to ~4x on the CI coverage runner (pcov
        // slows the builder's PHP lines while the chain's copy runs in C),
        // so the old 4x timing bar failed on load, not on a regression. The
        // allocation count is deterministic: it does not move with load, JIT
        // or the coverage driver.
        $gridCopyBytes = 16 * $w * $h;
        // A linear write may allocate a few small objects (a Cell, a
        // reference wrapper), never anything that scales with the canvas, so
        // the cap is a constant: 80000 bytes per grid copy against 4 KiB
        // leaves a 19x gap (measured: 0 bytes per builder write, 136728 per
        // chained write, on PHP 8.3 with and without pcov).
        $linearWriteCapBytes = 4096;

        $chain = Buffer::new($w, $h)->withCellAt(0, 0, $cell);
        $builder = Buffer::builder($w, $h)->setCellAt(0, 0, $cell);

        $chainMinBytes = PHP_INT_MAX;
        $builderMaxBytes = 0;
        $writes = 0;
        // Index 0 was the warm-up write above, so first-run allocations
        // (the by-reference wrapper on the builder grid) stay out of the
        // per-write figure.
        for ($i = 7; $i < $w * $h; $i += 7) {
            $col = $i % $w;
            $row = intdiv($i, $w);

            $base = memory_get_usage();
            memory_reset_peak_usage();
            $chain = $chain->withCellAt($col, $row, $cell);
            $chainMinBytes = min($chainMinBytes, memory_get_peak_usage() - $base);

            $base = memory_get_usage();
            memory_reset_peak_usage();
            $builder->setCellAt($col, $row, $cell);
            $builderMaxBytes = max($builderMaxBytes, memory_get_peak_usage() - $base);

            $writes++;
        }
        $built = $builder->toBuffer();

        // The probe must see the quadratic shape where it lives: every
        // chained write copies the whole grid. If this leg ever fails, the
        // probe has gone blind and the builder leg below proves nothing.
        self::assertGreaterThanOrEqual(
            $gridCopyBytes,
            $chainMinBytes,
            sprintf('the allocation probe must see the chain copy the %d-cell grid on every write (min %d bytes per write)', $w * $h, $chainMinBytes),
        );
        self::assertGreaterThan(
            $linearWriteCapBytes,
            $chainMinBytes,
            'the linear-write cap must reject the quadratic chain, or it guards nothing',
        );

        // The guard: no builder write allocates anything that scales with
        // the canvas, so a bulk build of $writes writes stays linear.
        self::assertLessThanOrEqual(
            $linearWriteCapBytes,
            $builderMaxBytes,
            sprintf(
                'bulk build must not keep the quadratic door (a builder write allocated %d bytes, cap %d; a grid copy is %d bytes, chain min %d bytes over %d writes)',
                $builderMaxBytes,
                $linearWriteCapBytes,
                $gridCopyBytes,
                $chainMinBytes,
                $writes,
            ),
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

    public function testBuilderWriteTimeDoesNotScaleWithTheCanvas(): void
    {
        // The allocation guard above is blind to a write that walks the grid
        // without copying it (a read-only validation loop, say): O(n) time
        // per write, 0 bytes. This leg times one builder write on a small and
        // on a 128x larger canvas. Both legs run the same PHP code, so load,
        // JIT and pcov slow them alike and cancel out of the ratio (the old
        // chain-vs-builder ratio did not cancel: the chain copies in C).
        // Interleaved rounds plus best-of-N keep a loaded runner from
        // inflating one side only.
        $cell = Cell::new('#');
        $smallCells = 8 * 8;
        $largeCells = 128 * 64;
        $small = Buffer::builder(8, 8);
        $large = Buffer::builder(128, 64);
        $smallWalk = array_fill(0, $smallCells, $cell);
        $largeWalk = array_fill(0, $largeCells, $cell);

        // ns per write, best of the batch; $walk adds a calibration grid walk.
        $time = static function (BufferBuilder $builder, int $cells, ?array $walk, int $writes) use ($cell): float {
            $width = $builder->width();
            $start = hrtime(true);
            for ($i = 0; $i < $writes; $i++) {
                $idx = ($i * 7) % $cells;
                $builder->setCellAt($idx % $width, intdiv($idx, $width), $cell);
                if ($walk !== null) {
                    foreach ($walk as $seen) {
                    }
                }
            }

            return (hrtime(true) - $start) / $writes;
        };

        $best = ['small' => INF, 'large' => INF, 'walkSmall' => INF, 'walkLarge' => INF];
        for ($round = 0; $round < 25; $round++) {
            $best['small'] = min($best['small'], $time($small, $smallCells, null, 256));
            $best['large'] = min($best['large'], $time($large, $largeCells, null, 256));
            $best['walkSmall'] = min($best['walkSmall'], $time($small, $smallCells, $smallWalk, 8));
            $best['walkLarge'] = min($best['walkLarge'], $time($large, $largeCells, $largeWalk, 8));
        }

        $cap = 8.0;
        $walkRatio = $best['walkLarge'] / $best['walkSmall'];
        $builderRatio = $best['large'] / $best['small'];

        // The probe must see a grid walk, or the guard below proves nothing.
        self::assertGreaterThan(
            $cap,
            $walkRatio,
            sprintf('the timing probe must see a per-write grid walk scale with the canvas (ratio %.1fx, cap %.1fx)', $walkRatio, $cap),
        );
        self::assertLessThan(
            $cap,
            $builderRatio,
            sprintf(
                'a builder write must cost the same on any canvas (%.0f ns on %d cells, %.0f ns on %d cells: %.1fx, cap %.1fx)',
                $best['small'],
                $smallCells,
                $best['large'],
                $largeCells,
                $builderRatio,
                $cap,
            ),
        );
    }

    public function testToBuilderSeedsFromTheExistingCellsWithoutMutatingTheSource(): void
    {
        $source = Buffer::new(4, 2)->withCellAt(1, 0, Cell::new('A'))->withCellAt(3, 1, Cell::new('B'));
        $before = $source->toAnsi();

        $builder = $source->toBuilder();
        self::assertSame($before, $builder->toBuffer()->toAnsi(), 'an untouched builder freezes to the seed');

        $builder->setCellAt(0, 0, Cell::new('Z'));
        self::assertSame($before, $source->toAnsi(), 'painting the builder must not change the source buffer');
        self::assertSame('Z', $builder->toBuffer()->cellAt(0, 0)->rune());
        self::assertSame('A', $builder->toBuffer()->cellAt(1, 0)->rune());
    }

    public function testToBuilderPaintingMatchesTheWithCellAtChainOverASeededFrame(): void
    {
        // A wide lead straddling a seeded continuation exercises the pair law
        // against pre-existing cells, not only against blank canvases.
        $seed = Buffer::new(6, 1)->withCellAt(0, 0, Cell::new('中', null, null, 2));
        $chain = $seed->withCellAt(1, 0, Cell::new('x'))->withCellAt(4, 0, Cell::new('界', null, null, 2));
        $builder = $seed->toBuilder()->setCellAt(1, 0, Cell::new('x'))->setCellAt(4, 0, Cell::new('界', null, null, 2));

        self::assertSame($chain->toAnsi(), $builder->toBuffer()->toAnsi());
    }

    public function testFromGridRefusesAWrongSizedGrid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BufferBuilder::fromGrid(2, 2, [Cell::blank(), Cell::blank(), Cell::blank()]);
    }

    public function testBuilderReportsItsDimensionsLikeTheBuffer(): void
    {
        $builder = Buffer::builder(7, 3);

        self::assertSame(7, $builder->width());
        self::assertSame(3, $builder->height());
        self::assertSame(7, $builder->toBuffer()->width());
        self::assertSame(3, $builder->toBuffer()->height());
    }

    public function testOversizedSeedMismatchIsRefusedBeforeTheCanvasIsAllocated(): void
    {
        // Regression (Codex P2): the constructor used to fill a blank 10^8-cell
        // canvas before fromGrid() saw the 1-cell seed, which exhausts the
        // memory limit with a fatal error that no caller can catch. The seed
        // must be refused first, as an InvalidArgumentException.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain exactly 100000000 cells');
        BufferBuilder::fromGrid(10000, 10000, [Cell::blank()]);
    }
}
