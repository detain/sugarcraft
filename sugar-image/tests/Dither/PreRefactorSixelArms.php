<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * C-06's pre-refactor arm map replay, shared by DithererTest and the two
 * fixtures that carry most of its cost (DithererGradDigestTest,
 * DithererNoiseDigestTest): about twenty seconds each, run as files of their
 * own so ParaTest, which hands a worker one whole file at a time, can run them
 * beside each other instead of one after the other.
 */
trait PreRefactorSixelArms
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
     * Every sixel arm of fixture $fk re-renders to its captured sha1.
     */
    private function assertFixtureArmsMatchTheirPreRefactorDigests(string $fk): void
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
}
