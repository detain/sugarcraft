<?php

declare(strict_types=1);

/**
 * skinny-seamcarve — content-aware CONDENSE of any existing .ansi logo to a
 * narrower grid (e.g. 78 → 40 cols), re-rastered at sextant / quadrant / half
 * resolution, written at tc / 256 / 16.
 *
 * How: the logo (last frame if animated) is rendered exactly (skRender: true
 * sextants/quadrants/blocks/braille, TTF for the rest), sampled to a sub-pixel
 * image at the TARGET raster's resolution per source cell (sextant: 2×3 per
 * cell), then
 *   1. SEAM CARVING removes the lowest-energy vertical seams first — black
 *      background, gaps between letters, empty margins — so letters get tight
 *      kerning instead of being squashed. --carve sets which fraction of the
 *      needed width reduction is done this way (0 = pure uniform downscale,
 *      1 = carve everything; 0.5-0.8 is usually best);
 *   2. the rest is an area-averaged uniform resample to W×H cells;
 *   3. optional tone restore (--gain/--sat/--gamma: averaging with black dims
 *      thin strokes);
 *   4. per depth the sub-pixels are quantised FIRST (256 → xterm nearest,
 *      16 → hue-preserving or nearest), then fitted to 2-colour cells.
 * Energy = gradient + brightness + saturation, so ink is expensive and black is
 * free. --protect boxes (source cells) are never carved. --seam=straight
 * removes whole straight columns only (keeps frames/verticals straight);
 * --seam=dp (default) lets seams wiggle around letters.
 * Text/box-drawing glyphs of the source become pixels (they will not survive
 * as text): re-add small text afterwards with skinny-cellops.php --text.
 *
 * Usage:
 *   php skinny-seamcarve.php <in.ansi> [options]
 *     --w=40                target width in cells (default 40)
 *     --h=N                 target height in cells (default: source height)
 *     --crop=x0-x1[:y0-y1]  use only this source cell box (inclusive)
 *     --carve=0.6           fraction of the width cut done by seam carving
 *     --vcarve=0            same for height (0 = area resample only)
 *     --seam=dp|straight    seam shape (default dp)
 *     --protect=x0-x1:y0-y1 source-cell box never carved (repeatable)
 *     --raster=sextant      sextant (2×3) | quadrant (2×2) | half (1×2)
 *     --gain=1 --sat=1 --gamma=1   tone restore after resampling
 *     --fill-bg=#000000     paint transparent sub-pixels this colour
 *     --mode16=hue|nearest  16-colour mapping (default hue)
 *     --dither=0            Bayer dither strength for 256/16 (0..1)
 *     --png=FILE.png        preview of the tc result (mixed-glyph style)
 *     --out=FILE.ansi       write the tc result to FILE (no jsonl)
 *     --slug=SLUG --write   write logo-SLUG-{tc,256,16}-WxH.ansi into the logos
 *                           dir and append logos.jsonl lines (--desc=, --tags=a,b,
 *                           --no-jsonl, --dir=DIR to write elsewhere)
 *     --print               cat the tc result to stdout
 *     -h | --help
 *
 * Example:
 *   php skinny-seamcarve.php ../logo-art-deco-gilt-tc-76x9.ansi --w=40 --carve=0.7 \
 *       --sat=1.15 --gain=1.1 --png=/tmp/adg.png --print
 *   php skinny-seamcarve.php ../logo-foo-tc-78x10.ansi --w=40 --h=9 --slug=foo-skinny \
 *       --write --desc="..." --tags=skinny,foo,seam-carved
 */

require_once __DIR__ . '/skinny-raster-kit.php';

$o = skOpts($argv);
if (isset($o['h']) && $o['h'] === true || isset($o['help']) || !$o['_']) {
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
    exit($o['_'] ? 0 : 2);
}

$in = $o['_'][0];
$cells = skLoad($in);
if (isset($o['crop'])) {
    [$xs, $ys] = array_pad(explode(':', (string) $o['crop']), 2, null);
    $xr = skRange($xs, skWidth($cells) - 1);
    $yr = $ys !== null ? skRange($ys, count($cells) - 1) : [0, count($cells) - 1];
    $cells = skCrop($cells, $xr[0], end($xr), $yr[0], end($yr));
}
$srcW = skWidth($cells);
$srcH = count($cells);
$W = (int) ($o['w'] ?? 40);
$H = (int) (is_string($o['h'] ?? null) ? $o['h'] : $srcH);
$mode = (string) ($o['raster'] ?? 'sextant');
[$sw, $sh] = skSub($mode);

// 1. exact render → sub-pixel image at target raster res per source cell
$gd = skRender($cells);
$img = skSample($gd, $srcW * $sw, $srcH * $sh, null, 0.5);

// protect mask in sub-pixels
$protect = [];
foreach ((array) ($o['protect'] ?? []) as $spec) {
    [$xs, $ys] = array_pad(explode(':', (string) $spec), 2, '0-');
    $xr = skRange($xs, $srcW - 1);
    $yr = skRange($ys, $srcH - 1);
    $protect[] = [$xr[0] * $sw, $yr[0] * $sh, (end($xr) + 1) * $sw - 1, (end($yr) + 1) * $sh - 1];
}

/** Per-pixel energy: ink is expensive, black/transparent is free. */
function scEnergy(array $img, array $protect): array
{
    $h = count($img);
    $w = count($img[0]);
    $lum = static fn (?array $c): float => $c === null ? 0.0 : (0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2]) / 255;
    $e = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = $img[$y][$x];
            $l = $lum($c);
            $sat = $c === null ? 0.0 : (max($c) - min($c)) / 255;
            $gx = abs($lum($img[$y][$x + 1] ?? null) - $lum($img[$y][$x - 1] ?? null));
            $gy = abs($lum($img[$y + 1][$x] ?? null) - $lum($img[$y - 1][$x] ?? null));
            $e[$y][$x] = 1.5 * $gx + 0.5 * $gy + 1.0 * $l + 0.6 * $sat;
        }
    }
    foreach ($protect as [$x0, $y0, $x1, $y1]) {
        for ($y = max(0, $y0); $y <= min($h - 1, $y1); $y++) {
            for ($x = max(0, $x0); $x <= min($w - 1, $x1); $x++) {
                $e[$y][$x] += 1e6;
            }
        }
    }
    return $e;
}

/** Remove $n vertical seams (dp = 8-connected minimal path, straight = whole columns). */
function scCarve(array $img, int $n, string $shape, array $protect): array
{
    // protect boxes shift as columns vanish: track original x per pixel
    $h = count($img);
    $origX = [];
    foreach ($img as $y => $row) {
        $origX[$y] = array_keys($row);
    }
    $energyFull = scEnergy($img, $protect);
    for ($s = 0; $s < $n; $s++) {
        $w = count($img[0]);
        // recompute gradient energy on the current image, keep protect via origX
        $e = scEnergy($img, []);
        foreach ($e as $y => $row) {
            foreach ($row as $x => $v) {
                if ($energyFull[$y][$origX[$y][$x]] >= 1e6) {
                    $e[$y][$x] += 1e6;
                }
            }
        }
        if ($shape === 'straight') {
            $best = 0;
            $bv = INF;
            for ($x = 0; $x < $w; $x++) {
                $t = 0.0;
                for ($y = 0; $y < $h; $y++) {
                    $t += $e[$y][$x];
                }
                if ($t < $bv) {
                    $bv = $t;
                    $best = $x;
                }
            }
            $path = array_fill(0, $h, $best);
        } else {
            $m = [$e[0]];
            $from = [];
            for ($y = 1; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $bx = $x;
                    $bv = $m[$y - 1][$x];
                    foreach ([$x - 1, $x + 1] as $px) {
                        if ($px >= 0 && $px < $w && $m[$y - 1][$px] < $bv) {
                            $bv = $m[$y - 1][$px];
                            $bx = $px;
                        }
                    }
                    $m[$y][$x] = $e[$y][$x] + $bv;
                    $from[$y][$x] = $bx;
                }
            }
            $x = array_search(min($m[$h - 1]), $m[$h - 1], true);
            $path = [];
            for ($y = $h - 1; $y >= 0; $y--) {
                $path[$y] = $x;
                $x = $from[$y][$x] ?? $x;
            }
        }
        foreach ($path as $y => $x) {
            array_splice($img[$y], $x, 1);
            array_splice($origX[$y], $x, 1);
        }
    }
    return $img;
}

function scTranspose(array $img): array
{
    $t = [];
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            $t[$x][$y] = $c;
        }
    }
    return $t;
}

/** Area-average resample of an rgb|null grid. */
function scResample(array $img, int $pw, int $ph): array
{
    $h = count($img);
    $w = count($img[0]);
    $gd = imagecreatetruecolor($w, $h);
    imagealphablending($gd, false);
    imagesavealpha($gd, true);
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            imagesetpixel($gd, $x, $y, $c === null ? imagecolorallocatealpha($gd, 0, 0, 0, 127) : imagecolorallocatealpha($gd, $c[0], $c[1], $c[2], 0));
        }
    }
    return skSample($gd, $pw, $ph, null, 0.5);
}

$carve = (float) ($o['carve'] ?? 0.6);
$vcarve = (float) ($o['vcarve'] ?? 0.0);
$shape = (string) ($o['seam'] ?? 'dp');
$curW = count($img[0]);
$cut = (int) round(max(0, $curW - $W * $sw) * $carve);
if ($cut > 0) {
    $img = scCarve($img, $cut, $shape, $protect);
}
$curH = count($img);
$vcut = (int) round(max(0, $curH - $H * $sh) * $vcarve);
if ($vcut > 0) {
    $tp = [];
    foreach ($protect as [$x0, $y0, $x1, $y1]) {
        $tp[] = [$y0, $x0, $y1, $x1];
    }
    $img = scTranspose(scCarve(scTranspose($img), $vcut, $shape, []));
}
$img = scResample($img, $W * $sw, $H * $sh);
$img = skTone($img, (float) ($o['gain'] ?? 1), (float) ($o['sat'] ?? 1), (float) ($o['gamma'] ?? 1));
if (isset($o['fill-bg'])) {
    $bgc = lkHex((string) $o['fill-bg']);
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            $img[$y][$x] = $c ?? $bgc;
        }
    }
}

$mode16 = (string) ($o['mode16'] ?? 'hue');
$dither = (float) ($o['dither'] ?? 0);
$out = [];
foreach (['tc', '256', '16'] as $depth) {
    $out[$depth] = lkEncode(skRaster($img, $mode, $depth, $mode16, $dither), $depth, [], 'nearest');
    [$w, $h] = lkVerify($out[$depth], $depth);
    lkCheckDepth($out[$depth], $depth, $depth);
}
fwrite(STDERR, sprintf("%s: %dx%d → %dx%d (%s, carved %d of %d sub-px cols)\n", basename($in), $srcW, $srcH, $w, $h, $mode, $cut, $curW - $W * $sw));
if (isset($o['png'])) {
    skPng(skParse($out['tc']), (string) $o['png']);
    fwrite(STDERR, "png → {$o['png']}\n");
}
if (isset($o['out'])) {
    file_put_contents((string) $o['out'], $out['tc']);
    fwrite(STDERR, "tc → {$o['out']}\n");
}
if (isset($o['print'])) {
    echo $out['tc'];
}
if (isset($o['write'])) {
    $slug = (string) ($o['slug'] ?? exit("--write needs --slug\n"));
    $dir = (string) ($o['dir'] ?? dirname(__DIR__));
    $desc = (string) ($o['desc'] ?? "Skinny seam-carved condense of " . basename($in));
    $tags = isset($o['tags']) ? explode(',', (string) $o['tags']) : ['skinny', 'seam-carved'];
    foreach ($out as $depth => $ansi) {
        $r = skWriteSkinny($dir, $slug, $depth, $ansi, "$desc ($depth)", $tags, !isset($o['no-jsonl']));
        fwrite(STDERR, "  wrote {$r['file']} (jsonl: {$r['jsonl']})\n");
    }
}
