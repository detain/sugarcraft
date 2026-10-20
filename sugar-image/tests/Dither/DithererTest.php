<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Dither\Ditherer;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\AsciiRenderer;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * C-06: equivalence pin for the Ditherer extraction.
 *
 * The S6.1 carry note demands the moved walk — rolling window, hole census
 * ordering, flatten, texture bias, B-13 memo and the S4.1 serpentine state —
 * reproduce the pre-refactor bytes EXACTLY. The arm map in
 * support/pre_refactor_digests.php was captured at base d958df4ee before any
 * edit (528 arms: five fixtures × six kernels × serpentine × intensity ×
 * colour budget, plus flatten/mlterm/quality/stream arms and every default
 * non-sixel renderer). Re-rendering each arm here and comparing sha1 digests
 * is the refactor proof; the partial-re-inline mutation (review brief) turns
 * the sixel legs of this map red.
 *
 * The direct-vs-delegate grid test additionally pins the wiring: the renderer
 * must produce the exact rows the Ditherer yields, so the extraction cannot
 * silently fork a second implementation.
 */
final class DithererTest extends TestCase
{
    /** Arm-map fixture key → file, mirroring the capture script. */
    private const FIXTURES = [
        'grad'     => 'gradient_64x64.png',
        'noise'    => '500x400_noise.png',
        'checker'  => 'checkerboard.png',
        'alpha2x2' => '2x2_transparent_halfblock.png',
        'alpha4b'  => 'halfblock_4branch.png',
    ];

    private const DITHERS = [
        'none'  => Dither::None,
        'fs'    => Dither::FloydSteinberg,
        'stuck' => Dither::Stucki,
        'atk'   => Dither::Atkinson,
        'ord'   => Dither::Ordered,
        'noise' => Dither::Noise,
    ];

    /**
     * @return \Generator<string, array{string}>
     */
    public static function fixtureKeys(): \Generator
    {
        foreach (array_keys(self::FIXTURES) as $key) {
            yield $key => [$key];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureKeys')]
    public function testEverySixelArmReRendersToItsPreRefactorDigest(string $fk): void
    {
        $map = require __DIR__ . '/support/pre_refactor_digests.php';
        $this->assertCount(528, $map, 'the equivalence map must stay whole');

        $img = ImageSource::fromFile(__DIR__ . '/../fixtures/' . self::FIXTURES[$fk]);
        $seen = 0;
        foreach ($map as $key => $digest) {
            $bytes = $this->renderArm($key, $img, $fk);
            if ($bytes === null) {
                continue;
            }
            $this->assertSame(
                $digest,
                sha1($bytes),
                "arm '$key' drifted from its pre-refactor bytes",
            );
            $seen++;
        }
        $this->assertGreaterThan(0, $seen, "map lost every arm of fixture $fk");
    }

    public function testDefaultNonSixelRenderersStayByteFrozen(): void
    {
        $map = require __DIR__ . '/support/pre_refactor_digests.php';
        foreach ($map as $key => $digest) {
            if (str_starts_with($key, 'six|')) {
                continue;
            }
            [$prefix, $fk] = explode('|', $key);
            $img = ImageSource::fromFile(__DIR__ . '/../fixtures/' . self::FIXTURES[$fk]);
            $bytes = match ($prefix) {
                'half'    => (new HalfBlockRenderer())->render($img, 8, 4),
                'quarter' => (new QuarterBlockRenderer())->render($img, 8, 4),
                // The capture script named the DEFAULT (TrueColor) renderer
                // 'asciimono' — the key spelling is historical; replay as captured.
                'asciimono' => (new AsciiRenderer())->render($img, 8),
                'asciitc' => (new AsciiRenderer(AsciiColorMode::TrueColor))->render($img, 8),
                'ascii256' => (new AsciiRenderer(AsciiColorMode::Ansi256))->render($img, 8),
                'ascii16' => (new AsciiRenderer(AsciiColorMode::Ansi16))->render($img, 8),
                'ramp10'  => (new AsciiRenderer(ramp: ' .:-=+*#%@'))->render($img, 8),
                default   => null,
            };
            $this->assertNotNull($bytes, "unknown non-sixel arm '$key' in the map");
            $this->assertSame($digest, sha1($bytes), "default path of '$key' moved — C-06 must not touch it");
        }
    }

    public function testRendererDelegateYieldsExactlyTheDithererRows(): void
    {
        $gd = imagecreatetruecolor(9, 5);
        self::assertIsGd($gd);
        imagealphablending($gd, false);
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $v = (int) round($x / 8 * 255);
                imagesetpixel($gd, $x, $y, (0xFF << 24) | ($v << 16) | ($v << 8) | $v);
            }
        }
        $palette = [[0, 0, 0], [128, 128, 128], [255, 255, 255]];

        foreach ([Dither::FloydSteinberg, Dither::Stucki, Dither::Atkinson] as $dither) {
            foreach ([true, false] as $snake) {
                $viaDelegate = iterator_to_array(self::callIndexRows($gd, $palette, $dither, $snake));
                $direct = iterator_to_array((new Ditherer(
                    dither: $dither,
                    texture: null,
                    intensity: null,
                    alphaThreshold: 127,
                    flattenBackground: null,
                    serpentine: $snake,
                ))->indexRows($gd, $palette, 0, 0));

                $this->assertSame(
                    $viaDelegate,
                    $direct,
                    "delegate and direct Ditherer walks diverged for {$dither->value} snake=" . ($snake ? 1 : 0),
                );
                $this->assertCount(5, $direct);
            }
        }
        imagedestroy($gd);
    }

    public function testLumaIndexRowsDiffusesBeforeTheThreshold(): void
    {
        // Three-step ramp over a flat mid-grey: the cold walk collapses to
        // one repeated step; FS must break it into neighbours of the bin
        // edges — the survey's "dither visibly works" pin (:368-373).
        $quantize = static function (float $v): array {
            $idx = max(0, min(2, (int) round($v / 255 * 2)));
            return [$idx, $idx * 128.0 > 255 ? 255.0 : $idx * 127.5];
        };

        $rows = array_fill(0, 3, array_fill(0, 40, 70.0));
        $cold = iterator_to_array(Ditherer::lumaIndexRows($rows, $quantize, Dither::None));
        $distinctCold = count(array_unique($cold[0]));

        $diffused = iterator_to_array(Ditherer::lumaIndexRows($rows, $quantize, Dither::FloydSteinberg));
        $distinctFs = count(array_unique($diffused[0]));

        $this->assertSame(1, $distinctCold, 'the cold quantiser must collapse a flat row to one step');
        $this->assertGreaterThan($distinctCold, $distinctFs, 'FS must break the flat row across ramp steps');
    }

    public function testLumaSerpentineMirrorsTheWalk(): void
    {
        // Within a row, FS hands 7/16 of each error to the NEXT visited
        // cell — forward that is x+1, on the snake x−1 — so the two walks
        // must disagree somewhere along an odd row of the same flat input
        // (a small unsaturated error keeps the divergence visible; the even
        // row is untouched by the flip and stays identical).
        $quantize = static function (float $v): array {
            $idx = max(0, min(8, (int) round($v / 255 * 8)));
            return [$idx, $idx * 31.875];
        };
        $row = array_fill(0, 16, 90.0);
        $grid = [$row, $row, $row];

        $straight = iterator_to_array(Ditherer::lumaIndexRows($grid, $quantize, Dither::FloydSteinberg));
        $snaked = iterator_to_array(Ditherer::lumaIndexRows($grid, $quantize, Dither::FloydSteinberg, serpentine: true));

        $this->assertSame($straight[0], $snaked[0], 'even rows must walk identically');
        $this->assertNotSame($straight[1], $snaked[1], 'odd rows must walk in reverse under serpentine');
    }

    /**
     * Drive the renderer's (reflection-pinned) private delegate.
     *
     * @param list<array{int,int,int}> $palette
     * @return \Generator<int, list<int>>
     */
    private static function callIndexRows(\GdImage $gd, array $palette, Dither $dither, bool $snake): \Generator
    {
        $method = new \ReflectionMethod(SixelRenderer::class, 'indexRows');
        $method->setAccessible(true);

        return $method->invokeArgs(
            new SixelRenderer($dither, 3, 8, 10, null, 127, null, $snake),
            [$gd, $palette, $dither, 0, 0],
        );
    }

    /**
     * Render one map arm post-refactor; null when the key belongs to another
     * fixture (the provider runs the map per fixture to bound each case).
     */
    private function renderArm(string $key, ImageSource $img, string $fk): ?string
    {
        if (!str_starts_with($key, 'six|')) {
            // Non-sixel arms run in their own byte-frozen test.
            return null;
        }
        $parts = explode('|', $key);
        if ($parts[1] !== $fk) {
            return null;
        }

        if (($parts[3] ?? '') === 'flat64') {
            return (new SixelRenderer(
                dither: self::DITHERS[$parts[2]], maxColors: 256, cellWidth: 8, cellHeight: 10,
                alphaThreshold: 64, flattenBackground: Color::rgb(255, 0, 255), serpentine: true,
            ))->render($img, 8, 6);
        }
        if (($parts[3] ?? '') === 'flat100') {
            return (new SixelRenderer(
                dither: self::DITHERS[$parts[2]], maxColors: 16, cellWidth: 8, cellHeight: 10,
                intensity: 0.25, alphaThreshold: 100, flattenBackground: Color::rgb(0, 20, 40),
            ))->render($img, 8, 6);
        }
        if (($parts[3] ?? '') === 'mlterm-q5') {
            return (new SixelRenderer(
                dither: self::DITHERS[$parts[2]], maxColors: 256, cellWidth: 8, cellHeight: 10,
                mltermEdgeRows: true, quality: 5, serpentine: true,
            ))->render($img, 8, 6);
        }
        if (($parts[3] ?? '') === 'q3') {
            return (new SixelRenderer(
                dither: self::DITHERS[$parts[2]], maxColors: 256, cellWidth: 8, cellHeight: 10,
                quality: 3, serpentine: true,
            ))->render($img, 8, 6);
        }
        if (($parts[4] ?? '') === 'stream8') {
            $r = new SixelRenderer(
                dither: Dither::FloydSteinberg, maxColors: 256, cellWidth: 8, cellHeight: 10, serpentine: true,
            );
            $stream = '';
            $r->encodeBandStream($img, 8, static function (string $c) use (&$stream): void {
                $stream .= $c;
            }, 6);

            return $stream;
        }

        // Basic grid arm: six|fk|dk|sN|iX|cN
        $snake = $parts[3] === 's1';
        $intensity = $parts[4] === 'in' ? null : (float) substr($parts[4], 1);
        $maxColors = (int) substr($parts[5], 1);

        return (new SixelRenderer(
            dither: self::DITHERS[$parts[2]],
            maxColors: $maxColors,
            cellWidth: 8,
            cellHeight: 10,
            intensity: $intensity,
            serpentine: $snake,
        ))->render($img, 8, 6);
    }

    private static function assertIsGd(mixed $gd): void
    {
        if (!$gd instanceof \GdImage) {
            throw new \RuntimeException('GD fixture creation failed');
        }
    }
}
