<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Levels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Levels\LevelsStretch;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * B-15: the auto-levels port — chafa pixops integer math, the Rec.709
 * saturation lift (with the corrected weights NTC-1 demands), the GD
 * hole exclusion the plan calls "loadBase alpha-exclusion", and the
 * sixel renderer wiring that keeps the whole pass default-OFF.
 */
final class LevelsStretchTest extends TestCase
{
    public function testLowContrastRampStretchesToFullRange(): void
    {
        $img = self::grayRamp(100, 150);
        LevelsStretch::stretch($img, 256);

        $lo = 255;
        $hi = 0;
        for ($x = 0; $x < 64; $x++) {
            $v = (imagecolorat($img, $x, 0) >> 16) & 0xFF;
            $lo = min($lo, $v);
            $hi = max($hi, $v);
        }
        self::assertSame(0, $lo, 'the cropped floor must land on black');
        self::assertSame(255, $hi, 'the cropped ceiling must land on white');
    }

    public function testBinaryBlackAndWhiteIsUnchanged(): void
    {
        // The survey's "fully-saturated input unchanged" pin: with mass
        // only at bin 0 and bin 2040 the crop can never move the bounds
        // off them, and the identity factor keeps every byte.
        $img = imagecreatetruecolor(64, 64);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $v = (($x + $y) & 1) === 0 ? 0 : 255;
                imageSetPixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        LevelsStretch::stretch($img, 256);

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $at = imagecolorat($img, $x, $y);
                $expected = (($x + $y) & 1) === 0 ? 0 : 255;
                self::assertSame($expected, ($at >> 16) & 0xFF);
                self::assertSame($expected, ($at >> 8) & 0xFF);
                self::assertSame($expected, $at & 0xFF);
            }
        }
    }

    public function testUniformGrayCanvasIsUnchanged(): void
    {
        // min == max bails normalize_rgb; and because the corrected
        // Rec.709 weights sum to exactly 1.0, P of a gray is the gray
        // itself, so the boost pass is also a no-op. chafa's typo'd
        // weights (.299/.587/.144) sum to 1.030 and would NOT be —
        // this pin discriminates NTC-1 on the invariant side.
        $img = imagecreatetruecolor(32, 32);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                imageSetPixel($img, $x, $y, (120 << 16) | (120 << 8) | 120);
            }
        }

        LevelsStretch::stretch($img, 256);

        $at = imagecolorat($img, 16, 16);
        self::assertSame(120, ($at >> 16) & 0xFF);
        self::assertSame(120, ($at >> 8) & 0xFF);
        self::assertSame(120, $at & 0xFF);
    }

    public function testHolesAreExcludedFromTheHistogramAndUntouched(): void
    {
        // The containment pin: a full quadrant of holed pixels at rgb
        // 250 must neither drag the upper bound up nor be rewritten by
        // the stretch themselves — the plan's loadBase alpha-exclusion.
        $img = imagecreatetruecolor(64, 64);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $v = 100 + intdiv($x * 50, 31);
                imageSetPixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
            for ($x = 32; $x < 64; $x++) {
                imageSetPixel($img, $x, $y, (127 << 24) | (250 << 16) | (250 << 8) | 250);
            }
        }

        LevelsStretch::stretch($img, 256);

        // Left half: cropped floor and ceiling reached — impossible if
        // the holed 250s had been counted (the ceiling would stop well
        // short of 255 for the low end of the ramp).
        $lo = 255;
        $hi = 0;
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $v = (imagecolorat($img, $x, $y) >> 16) & 0xFF;
                $lo = min($lo, $v);
                $hi = max($hi, $v);
            }
        }
        self::assertSame(0, $lo);
        self::assertSame(255, $hi);

        // Right half: bytes verbatim, alpha verbatim.
        for ($y = 0; $y < 64; $y++) {
            $at = imagecolorat($img, 48, $y);
            self::assertSame(127, $at >> 24);
            self::assertSame(250, ($at >> 16) & 0xFF);
            self::assertSame(250, ($at >> 8) & 0xFF);
            self::assertSame(250, $at & 0xFF);
        }
    }

    public function testBoostLiftsSaturationWithRec709Weights(): void
    {
        // P(200,100,100) = sqrt(0.2126*40000 + 0.7152*10000 + 0.0722*10000)
        // = sqrt(16378) = 127.9765..., so r = 2*200 - P -> 272 -> clamp 255,
        // and g/b = 2*100 - P -> 72.023 -> (int) 72. With chafa's typo'd
        // .299/.587/.144 weights P would be sqrt(19270) = 138.816 and g/b
        // would truncate to 61 — this pin fails if the typo is ever copied.
        self::assertSame([255, 72, 72], LevelsStretch::boost([200, 100, 100]));
        self::assertSame([255, 0, 0], LevelsStretch::boost([255, 0, 0]));
        self::assertSame([0, 0, 0], LevelsStretch::boost([0, 0, 0]));
        self::assertSame([100, 100, 100], LevelsStretch::boost([100, 100, 100]));
    }

    public function testFastIntensityAndCropLadderMirrorChafa(): void
    {
        self::assertSame(2040, LevelsStretch::intensity(255, 255, 255));
        self::assertSame(800, LevelsStretch::intensity(100, 100, 100));
        self::assertSame(3 * 20 + 4 * 30 + 40, LevelsStretch::intensity(20, 30, 40));
        self::assertSame(5, LevelsStretch::cropPct(16));
        self::assertSame(10, LevelsStretch::cropPct(8));
        self::assertSame(20, LevelsStretch::cropPct(2));
        self::assertSame(20, LevelsStretch::cropPct(256));
    }

    public function testCalcBoundsCropsTheTailsWithChafaIntegerMath(): void
    {
        $hist = [100 => 5, 200 => 5];
        $bounds = self::invokeCalcBounds($hist, 10, 20);
        self::assertSame([100, 200], $bounds);

        // n too small for a 20 % crop: (10 * 204) / 1024 == 1, but a
        // 64 px canvas at 2 % crop can round to 0 mass — then the first
        // bin (empty) breaks the scan and the bounds are the full range.
        self::assertSame([0, 2047], self::invokeCalcBounds($hist, 4, 20));
    }

    public function testNormalizeChannelTruncatesLikeTheC(): void
    {
        $norm = new \ReflectionMethod(LevelsStretch::class, 'normalizeCh');
        $norm->setAccessible(true);
        // factor 4096 is the identity; the floor shifts and both ends clamp.
        self::assertSame(210, $norm->invoke(null, 255, 45, 4096));
        self::assertSame(0, $norm->invoke(null, 10, 45, 4096));
        // Negative intermediates truncate toward zero (C `/`), then clamp:
        // (10 - 45) * 5000 = -175000; -175000 / 4096 = -42 (not -43).
        self::assertSame(0, $norm->invoke(null, 10, 45, 5000));
        // (58 - 45) * 1590 = 20670; intdiv by 4096 is 5 — the stretch
        // is linear between floor and range.
        self::assertSame(5, $norm->invoke(null, 58, 45, 1590));
    }

    public function testAutoLevelsIsDefaultOffAndThreadsEveryWither(): void
    {
        $base = new SixelRenderer();
        self::assertFalse($base->autoLevels());
        self::assertStringNotContainsString('autolevels', $base->configFingerprint());

        $on = $base->withAutoLevels();
        self::assertTrue($on->autoLevels());
        self::assertStringEndsWith('|autolevels=1', $on->configFingerprint());
        self::assertFalse($on->withAutoLevels(false)->autoLevels());

        $carried = [
            $on->withDither(Dither::Stucki),
            $on->withAlphaThreshold(64),
            $on->withFlatten(null),
            $on->withSerpentine(true),
            $on->withMltermEdgeRows(true),
            $on->withQuality(5),
        ];
        foreach ($carried as $renderer) {
            self::assertTrue($renderer->autoLevels(), 'withers must not silently drop the levels pass');
        }
    }

    public function testArmedRenderDiffersAndDefaultStaysByteFrozen(): void
    {
        $src = imagecreatetruecolor(64, 64);
        self::assertNotFalse($src);
        imagealphablending($src, false);
        imagesavealpha($src, true);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $v = 100 + intdiv(($x + $y) * 50, 126);
                imageSetPixel($src, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        $image = \SugarCraft\Image\ImageSource::fromGd($src, 'image/png');
        imagedestroy($src);

        $off = new SixelRenderer(dither: Dither::None, maxColors: 16);
        $armed = $off->withAutoLevels();
        self::assertNotSame($off->render($image, 8, 6), $armed->render($image, 8, 6));
        self::assertSame($off->render($image, 8, 6), $off->render($image, 8, 6));
    }

    /**
     * @param array<int,int> $hist
     * @return array{int,int}
     */
    private static function invokeCalcBounds(array $hist, int $samples, int $cropPct): array
    {
        $m = new \ReflectionMethod(LevelsStretch::class, 'calcBounds');
        $m->setAccessible(true);

        /** @var array{int,int} $r */
        $r = $m->invoke(null, $hist, $samples, $cropPct);

        return $r;
    }

    private static function grayRamp(int $lo, int $hi): \GdImage
    {
        $img = imagecreatetruecolor(64, 64);
        self::assertNotFalse($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $v = $lo + intdiv($x * ($hi - $lo), 63);
                imageSetPixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        return $img;
    }
}
