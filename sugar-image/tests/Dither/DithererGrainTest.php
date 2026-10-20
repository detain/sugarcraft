<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Dither\Ditherer;

/**
 * C-09: grain diffusion — chafa's fs_dither_grain shape ported to the
 * Ditherer: one mean, one nearest lookup, one error spread per
 * grainWidth x grainHeight block, the pixel walk (1x1) byte-untouched.
 * Palette in every pin: [black, grey-200]; flat base (100,100,100) so
 * the first grain ties to black (Euclidean tie -> lowest index) and the
 * diffused +43.75 lands past the midpoint on the far pen — the minimal
 * fixture where "which pixels see error" is fully visible.
 */
final class DithererGrainTest extends TestCase
{
    /** @var list<array{int,int,int}> */
    private const PALETTE = [[0, 0, 0], [200, 200, 200]];

    public function testGrainPaintsEveryPixelOfABlockWithOneIndex(): void
    {
        $img = self::flat(4, 2, 100);
        $rows = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2), $img);

        // Both pixel rows of the grain row, every column inside a grain.
        self::assertSame([0, 0, 1, 1], $rows[0]);
        self::assertSame($rows[0], $rows[1]);
    }

    public function testPixelModeDiffusesWithinAGrainWhereGrainModeCannot(): void
    {
        $img = self::flat(4, 2, 100);
        $pixel = self::collect(new Ditherer(dither: Dither::FloydSteinberg), $img);
        $grain = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2), $img);

        // The survey's "one error term, not four": column 1 of a 2x2 grain
        // never sees the intra-row tap — while the 1x1 walk flips it to the
        // far pen immediately.
        self::assertSame(1, $pixel[0][1], 'pixel mode must cascade inside the block');
        self::assertSame(0, $grain[0][1], 'grain mode must not');
        self::assertNotSame($pixel[0], $grain[0]);
    }

    public function testGrainErrorReachesTheNextGrainRow(): void
    {
        $img = self::flat(2, 4, 100);
        $rows = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2), $img);

        // One grain column, two grain rows: gy0's (0,1) 5/16 tap lands on
        // gy1 — 100 + 31.25 crosses the black/200 midpoint, so the next
        // grain row flips to the far pen. Rows are per-PIXEL (w columns).
        self::assertSame([0, 0], $rows[0]);
        self::assertSame([1, 1], $rows[2]);
        self::assertCount(4, $rows);
    }

    public function testSerpentineMirrorsTheGrainWalk(): void
    {
        // 3 grain columns over 6 px, 2 grain rows over 4 px: grain row 0 is
        // flat 60, grain row 1 steps 60/67/74 per column. With the
        // 5/16+3/16 taps from row 0 in play, the cascade direction on row 1
        // is visible: forward picks [black,200,black], the reversed walk
        // with mirrored taps picks [200,black,200].
        $img = imagecreatetruecolor(6, 4);
        self::assertNotFalse($img);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 6; $x++) {
                $v = $y < 2 ? 60 : 60 + 7 * intdiv($x, 2);
                imageSetPixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        $straight = self::collect(
            new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2),
            $img,
        );
        $snaked = self::collect(
            new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2, serpentine: true),
            $img,
        );

        // Grain row 0 walks forward in both; grain row 1 reverses only when
        // armed — the flip lands on the grain grid, block-uniform still.
        self::assertSame($straight[0], $snaked[0]);
        self::assertSame([0, 0, 1, 1, 0, 0], $straight[2]);
        self::assertSame([1, 1, 0, 0, 1, 1], $snaked[2]);
        self::assertSame($straight[2], $straight[3]);
    }

    public function testAllHoleGrainIsUnpaintedAndItsRgbNeverLeaks(): void
    {
        // offset 1: the right grain is fully holed but carries different
        // colours in the two runs — the outputs must be identical, and the
        // hole slots must sit at index 0 (the background register), never
        // at the looked-up pen.
        $dim = self::halfHoled(10, 10, 240);
        $bright = self::halfHoled(250, 30, 10);

        $a = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2), $dim, 1);
        $b = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 2, grainHeight: 2), $bright, 1);

        self::assertSame($a, $b);
        // Opaque side at offset 1 indexes from register 1; the holed grain
        // leaves its slots at 0 — the background register — untouched.
        self::assertSame([1, 1, 0, 0], $a[0]);
        self::assertSame($a[0], $a[1]);
    }

    public function testGrainMustDivideTheCanvas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must divide the canvas evenly');

        iterator_to_array(
            (new Ditherer(grainWidth: 2, grainHeight: 2))->indexRows(self::flat(3, 2, 100), self::PALETTE, 0),
        );
    }

    public function testGrainMessageNamesTheGeometry(): void
    {
        try {
            iterator_to_array(
                (new Ditherer(grainWidth: 2, grainHeight: 2))->indexRows(self::flat(3, 2, 100), self::PALETTE, 0),
            );
            self::fail('expected the divisibility refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('3x2', $e->getMessage());
            self::assertStringContainsString('2x2', $e->getMessage());
        }
    }

    public function testSubPixelGrainIsRefusedAtConstruction(): void
    {
        foreach ([0, -1] as $side) {
            try {
                new Ditherer(grainWidth: $side);
                self::fail('expected refusal for grain width ' . $side);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('at least 1 pixel', $e->getMessage());
            }
        }
    }

    public function testNonPowerOfTwoGrainMeansByFloorOverOpaqueCount(): void
    {
        $img = self::flat(6, 2, 100);
        $rows = self::collect(new Ditherer(dither: Dither::FloydSteinberg, grainWidth: 3, grainHeight: 2), $img);

        // 2 grain columns; gx1 receives 7/16 of gx0's error.
        self::assertSame([0, 0, 0, 1, 1, 1], $rows[0]);
        self::assertSame($rows[0], $rows[1]);
    }

    public function testAccessorsAndDefaultShape(): void
    {
        $plain = new Ditherer();
        self::assertSame(1, $plain->grainWidth());
        self::assertSame(1, $plain->grainHeight());

        // 1x1 explicit never trips the divisibility law (odd geometry renders
        // on the byte-frozen pixel walk).
        $armed = new Ditherer(grainWidth: 1, grainHeight: 1);
        $rows = self::collect($armed, self::flat(3, 1, 100));
        self::assertCount(1, $rows);
        self::assertSame([0, 1, 0], $rows[0]);
    }

    /**
     * @param \Generator<int, list<int>> $gen
     * @return array<int, list<int>>
     */
    private static function collect(Ditherer $ditherer, \GdImage $img, int $offset = 0): array
    {
        return iterator_to_array($ditherer->indexRows($img, self::PALETTE, $offset));
    }

    private static function flat(int $w, int $h, int $v): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        self::assertNotFalse($img);
        $pen = ($v << 16) | ($v << 8) | $v;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imageSetPixel($img, $x, $y, $pen);
            }
        }

        return $img;
    }

    private static function halfHoled(int $holeR, int $holeG, int $holeB): \GdImage
    {
        $img = imagecreatetruecolor(4, 2);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 2; $x++) {
                imageSetPixel($img, $x, $y, (100 << 16) | (100 << 8) | 100);
            }
            for ($x = 2; $x < 4; $x++) {
                imageSetPixel($img, $x, $y, (127 << 24) | ($holeR << 16) | ($holeG << 8) | $holeB);
            }
        }

        return $img;
    }
}
