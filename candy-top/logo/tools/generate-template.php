<?php

declare(strict_types=1);

/**
 * generate-template — starting point for a candy-top logo generator.
 *
 * Copy to tools/generate-<slug>.php, set SLUG, then replace the DESIGN DATA
 * and the build functions with your own design. The shared pipeline (half
 * blocks, cell grids, tc→256→16, width/depth verification, animation framing,
 * file + logos.jsonl writing) lives in logo-kit.php — require it, don't fork
 * it; if you need something it lacks, add it locally in your generator.
 *
 * Usage:
 *   php generate-<slug>.php            preview tc, 256, 16 to stdout (+ verify)
 *   php generate-<slug>.php --write    write .ansi files + logos.jsonl lines
 *
 * Other designers' generators in this directory are worth reading for
 * techniques (facet shading, layered bands, glints, pinned 16-colour maps).
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'template';              // ← change: your campaign slug
const TEXT = 'CANDY TOP';

/** Pixel font: '#' = ink. 7 px tall → 4 terminal rows after half-blocking. */
const FONT = [
    'C' => ['.####', '#....', '#....', '#....', '#....', '#....', '.####'],
    'A' => ['.###.', '#...#', '#...#', '#####', '#...#', '#...#', '#...#'],
    'N' => ['#...#', '##..#', '#.#.#', '#.#.#', '#..##', '#...#', '#...#'],
    'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
    'Y' => ['#...#', '#...#', '.#.#.', '..#..', '..#..', '..#..', '..#..'],
    'T' => ['#####', '..#..', '..#..', '..#..', '..#..', '..#..', '..#..'],
    'O' => ['.###.', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
];

const GRADIENT = ['#FF5FA2', '#FFB35C', '#7CE38B', '#5CC8FF'];  // left → right
const SHADOW = '#3A2440';
const PINS16 = [];                    // optional: '#RRGGBB' => 30-37/90-97
const DESCRIPTION = 'Template logo: pixel-font CANDY TOP with a horizontal gradient and drop shadow.';
const TAGS = ['template', 'half-block-pixels', 'horizontal-gradient', 'drop-shadow'];

const ANIM = true;                    // also emit <slug>-anim files
const ANIM_FRAMES = 12;               // play with: php logo-play.php <file> 100

// ================================================================ BUILD

/** @return list<list<?array>> rgb|null pixel rows */
function pixels(?float $glint = null): array
{
    [$mask] = lkLayout(FONT, TEXT, letterGap: 1, wordGap: 3, margin: 1);
    $h = count($mask) + 1;                  // +1 row for the shadow
    $w = strlen($mask[0]) + 1;
    $on = static fn (int $x, int $y): bool => ($mask[$y][$x] ?? '.') === '#';
    $px = array_fill(0, $h, array_fill(0, $w, null));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($on($x, $y)) {
                $c = lkGradient(GRADIENT, $x / ($w - 1));
                $c = lkShade($c, 0.25 - 0.5 * $y / $h);          // lit top, dark base
                if ($glint !== null && abs($x - $glint + $y) < 2) {
                    $c = lkMix($c, [255, 255, 255], 0.7);
                }
                $px[$y][$x] = $c;
            } elseif ($on($x - 1, $y - 1)) {
                $px[$y][$x] = lkHex(SHADOW);
            }
        }
    }
    return $px;
}

function cells(?float $glint = null): array
{
    return lkHalfBlock(pixels($glint));
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
if ($write && SLUG === 'template') {
    exit("set SLUG before --write\n");
}
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $static = lkEncode(cells(), $depth, PINS16);
    [$w, $h] = lkVerify($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    if (ANIM) {
        $width = count(cells()[0]);
        $frames = [];
        for ($f = 0; $f < ANIM_FRAMES; $f++) {
            $frames[] = lkEncode(cells(-6 + ($width + 12) * $f / (ANIM_FRAMES - 1)), $depth, PINS16);
        }
        $anim = lkAnim($frames, $static);
        if ($write) {
            $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . " Glint sweep, play with tools/logo-play.php ($depth)", [...TAGS, 'animated']);
            fwrite(STDERR, "  wrote {$r['file']}\n");
        }
    }
}
