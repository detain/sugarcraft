<?php

/**
 * C-08: serpentine (boustrophedon) diffusion walk. Pins: the byte-frozen
 * defaults (OFF) for all three diffusion kernels against shas captured at
 * base 53ecc09f1 before the knob existed, the mirrored end-to-end cascade
 * proving reversed rows push in-row error LEFT (both known mutations —
 * dropped negate and walk-not-reversed — land exact-red here), per-kernel
 * tap mirroring at the diffuseError unit boundary, the Ordered/None no-op
 * gate, and the wither/accessor/fingerprint carry discipline.
 */

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

final class SixelSerpentineTest extends TestCase
{
    /**
     * Default-path (serpentine absent/false) encodings of the
     * c08Gray5Fixture at a 3-colour budget, 1×1 cells, captured against
     * the BASE tree at 53ecc09f1 — the knob did not exist there, so these
     * three shas ARE the byte-frozen default for every diffusion kernel.
     */
    private const BASE_FS_SHA1 = 'e9fdfa405063279a5cbbd415e6ff23d0c6d43594';
    private const BASE_STUCKI_SHA1 = '626d6d1278549d48bec57fa41ede12d9372a4f8e';
    private const BASE_ATKINSON_SHA1 = '380b376209978497664f1aa89600b6fe8ea6d77d';

    /**
     * Two-colour palette used by every indexRows probe below: black is
     * index 0, white index 1, so a yielded grid row is the raw pin.
     *
     * @var list<array{int,int,int}>
     */
    private const BLACK_WHITE = [[0, 0, 0], [255, 255, 255]];

    private function c08Gray5Fixture(): ImageSource
    {
        // Five grey levels against a 3-colour budget keeps quantisation
        // error large on every pixel — the regime where the walk direction
        // can change decisions. (A gentler fixture was measured vacuous:
        // when median-cut reproduces the source colours exactly, error is
        // identically zero and no snake can move a byte — disclosed in the
        // lane REPORT.)
        $grays = [10, 60, 115, 200, 250];
        $im = imagecreatetruecolor(12, 8);
        imagealphablending($im, false);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 12; $x++) {
                $g = $grays[($x + $y * 3) % 5];
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $g, $g, $g));
            }
        }
        $source = ImageSource::fromGd($im, 'image/png');
        imagedestroy($im);

        return $source;
    }

    /**
     * Run the private generator directly on raw GD pixels — the grids it
     * yields are the exact diffusion bookkeeping, with no sixel encoding
     * layer between the pins and the walk.
     *
     * @param list<list<int>> $values one grey level per pixel
     * @return array<int, list<int>>
     */
    private function c08Grid(SixelRenderer $renderer, array $values): array
    {
        $h = count($values);
        $w = count($values[0]);
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        for ($y = 0; $y < $h; $y++) {
            foreach ($values[$y] as $x => $v) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $v, $v, $v));
            }
        }

        $method = new \ReflectionMethod(SixelRenderer::class, 'indexRows');
        $method->setAccessible(true);
        /** @var \Generator<int, list<int>> $gen */
        $gen = $method->invokeArgs($renderer, [$im, self::BLACK_WHITE, Dither::FloydSteinberg, 0, 0]);
        $grid = iterator_to_array($gen);
        imagedestroy($im);

        return $grid;
    }

    public function testSerpentineIsOffByDefaultAndByteFrozen(): void
    {
        $image = $this->c08Gray5Fixture();

        $this->assertFalse((new SixelRenderer())->serpentine(), 'the shipped default must be OFF');

        $fs = new SixelRenderer(Dither::FloydSteinberg, 3, 1, 1);
        $default = $fs->render($image, 12, 8);
        $this->assertSame(self::BASE_FS_SHA1, sha1($default));
        $this->assertSame($default, $fs->withSerpentine(false)->render($image, 12, 8));

        // Arming moves bytes on this high-error fixture (the knob is live
        // end-to-end, not a decorative field) and is deterministic.
        $snaked = $fs->withSerpentine(true);
        $this->assertTrue($snaked->serpentine());
        $first = $snaked->render($image, 12, 8);
        $this->assertNotSame($default, $first);
        $this->assertSame($first, $snaked->render($image, 12, 8));

        // The two other diffusion arms stay byte-frozen OFF as well.
        $this->assertSame(
            self::BASE_STUCKI_SHA1,
            sha1((new SixelRenderer(Dither::Stucki, 3, 1, 1))->render($image, 12, 8)),
        );
        $this->assertSame(
            self::BASE_ATKINSON_SHA1,
            sha1((new SixelRenderer(Dither::Atkinson, 3, 1, 1))->render($image, 12, 8)),
        );
    }

    public function testForwardRowCarriesInRowErrorRightward(): void
    {
        // [120,0,120,0,0] on a single row: x0 greys to black (120 is closer
        // to 0 than 255), its 7/16 error lifts x1 to 52.5 (still black),
        // and x1's 7/16 lifts x2 to ~143 — over the midpoint, so the SECOND
        // grey quantises white and the cascade marker sits at x2.
        $grid = $this->c08Grid(new SixelRenderer(), [[120, 0, 120, 0, 0]]);
        $this->assertSame([0, 0, 1, 0, 0], $grid[0]);
    }

    public function testReversedOddRowCarriesInRowErrorLeftward(): void
    {
        // Same cascade on the ODD row, walked right→left with a mirrored
        // kernel: the second grey diffuses first and the lifted marker
        // lands on x0 instead of x2. Both known mutations die on this
        // exact grid: dropping the dx negate pushes error into
        // already-visited pixels (marker vanishes), and reversing the walk
        // without mirroring the kernel does the same across the left wall.
        $renderer = (new SixelRenderer())->withSerpentine(true);
        $grid = $this->c08Grid($renderer, [[0, 0, 0, 0, 0], [120, 0, 120, 0, 0]]);

        $this->assertSame([0, 0, 0, 0, 0], $grid[0], 'even rows keep the forward walk');
        $this->assertSame([1, 0, 0, 0, 0], $grid[1], 'the reversed row must flow left');

        // And the straight walk on the same canvas keeps the marker at x2.
        $straight = $this->c08Grid(new SixelRenderer(), [[0, 0, 0, 0, 0], [120, 0, 120, 0, 0]]);
        $this->assertSame([0, 0, 1, 0, 0], $straight[1]);
    }

    /**
     * @return array<string, array{Dither, list<array{int,int,float}>}>
     */
    public static function mirroredKernelTables(): array
    {
        // Canonical taps spelled out independently of the implementation
        // (same discipline as DitherKernelTest::CANONICAL), mirrored by
        // negating dx for the reversed pass.
        return [
            'floyd-steinberg' => [Dither::FloydSteinberg, [
                [-1, 0, 7.0 / 16.0],
                [1, 1, 3.0 / 16.0],
                [0, 1, 5.0 / 16.0],
                [-1, 1, 1.0 / 16.0],
            ]],
            'stucki' => [Dither::Stucki, [
                [-1, 0, 8.0 / 42.0],
                [-2, 0, 4.0 / 42.0],
                [2, 1, 2.0 / 42.0],
                [1, 1, 4.0 / 42.0],
                [0, 1, 8.0 / 42.0],
                [-1, 1, 4.0 / 42.0],
                [-2, 1, 2.0 / 42.0],
                [2, 2, 1.0 / 42.0],
                [1, 2, 2.0 / 42.0],
                [0, 2, 4.0 / 42.0],
                [-1, 2, 2.0 / 42.0],
                [-2, 2, 1.0 / 42.0],
            ]],
            'atkinson' => [Dither::Atkinson, [
                [-1, 0, 1.0 / 8.0],
                [-2, 0, 1.0 / 8.0],
                [1, 1, 1.0 / 8.0],
                [0, 1, 1.0 / 8.0],
                [-1, 1, 1.0 / 8.0],
                [0, 2, 1.0 / 8.0],
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mirroredKernelTables')]
    public function testReversedPassMirrorsEveryKernelTap(Dither $dither, array $taps): void
    {
        $w = 7;
        $h = 5;
        $x = 3;
        $y = 1;

        $accum = [];
        for ($row = 0; $row < $h; $row++) {
            $line = [];
            for ($col = 0; $col < $w; $col++) {
                $line[] = [0.0, 0.0, 0.0];
            }
            $accum[] = $line;
        }

        $method = new \ReflectionMethod(SixelRenderer::class, 'diffuseError');
        $method->setAccessible(true);
        $method->invokeArgs(new SixelRenderer(), [&$accum, $w, $h, $x, $y, 1.0, 0.0, 0.0, $dither, -1]);

        $expected = array_fill(0, $h, array_fill(0, $w, 0.0));
        foreach ($taps as [$dx, $dy, $factor]) {
            $expected[$y + $dy][$x + $dx] = $factor;
        }
        for ($row = 0; $row < $h; $row++) {
            for ($col = 0; $col < $w; $col++) {
                $this->assertSame(
                    $expected[$row][$col],
                    $accum[$row][$col][0],
                    sprintf('tap (%d,%d) of %s reversed', $col - $x, $row - $y, $dither->value),
                );
                $this->assertSame(0.0, $accum[$row][$col][1], 'green stays cold for an R-only error');
                $this->assertSame(0.0, $accum[$row][$col][2], 'blue stays cold for an R-only error');
            }
        }
        $this->assertSame(0.0, $accum[$y][$x][0], 'the diffusing pixel itself stays untouched');
    }

    public function testOrderedAndNoneRenderByteIdenticallySnaked(): void
    {
        // Serpentine is gated to the diffusion arms: Ordered is a
        // position-taken bias and None has no walk to reverse, so arming
        // the snake on either must not move a single byte.
        $image = $this->c08Gray5Fixture();
        foreach ([Dither::Ordered, Dither::None] as $dither) {
            $straight = (new SixelRenderer($dither, 3, 1, 1))->render($image, 12, 8);
            $snaked = (new SixelRenderer($dither, 3, 1, 1))->withSerpentine(true)->render($image, 12, 8);
            $this->assertSame($straight, $snaked, $dither->value . ' must ignore the snake');
        }
    }

    public function testFingerprintAndWithersCarryTheSnake(): void
    {
        $frozen = 'dither=floyd-steinberg|maxColors=3|cellWidth=1|cellHeight=1';
        $renderer = new SixelRenderer(Dither::FloydSteinberg, 3, 1, 1);

        $this->assertSame($frozen, $renderer->configFingerprint());
        $this->assertSame(
            $frozen . '|serpentine=1',
            $renderer->withSerpentine()->configFingerprint(),
        );

        // Dither and alpha switches carry the armed snake.
        $carried = $renderer->withSerpentine()->withDither(Dither::Stucki)->withAlphaThreshold(100);
        $this->assertTrue($carried->serpentine());
        $this->assertSame(100, $carried->alphaThreshold());
        $this->assertSame(Dither::Stucki, $carried->dither());
    }
}
