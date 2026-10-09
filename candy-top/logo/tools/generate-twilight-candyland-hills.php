<?php

declare(strict_types=1);

/**
 * generate-twilight-candyland-hills — a painted dusk landscape in sextants.
 *
 * Scene (back → front): a violet→rose→apricot twilight sky with stars and a
 * crescent moon, a shooting star, hazy far hills, rolling mint candy hills
 * lit by a warm rim light, swirl lollipop trees and gumdrop dots; in front,
 * "CANDY TOP" set in a real TTF (smooth curves, not a pixel font) with a
 * sunset-lit butterscotch→strawberry fill, top bevel highlight, gloss band,
 * bottom shade, a dark plum outline and a cast shadow.
 *
 * Everything is painted analytically at (2W)×(3H) sub-pixels and fitted to
 * sextant glyphs by tools/sextant-image-raster.php (best fg/bg pair per cell).
 * 256/16 are quantised per sub-pixel BEFORE fitting, so cells only use real
 * palette colours.
 *
 * Usage:
 *   php generate-twilight-candyland-hills.php                 preview + verify
 *   php generate-twilight-candyland-hills.php --write         write .ansi + jsonl
 *   php generate-twilight-candyland-hills.php --png=<dir>     raw sub-pixel PNGs
 *   --no-anim                                                  skip the animation (faster --write)
 */

require __DIR__ . '/sextant-image-raster.php';

// ================================================================ DESIGN DATA

const SLUG = 'twilight-candyland-hills';
const W = 78;                      // cells
const H = 10;
const PX_W = W * 2;                // sub-pixels
const PX_H = H * 3;
const ASPECT = 4 / 3;              // a sub-pixel is 3 wide : 4 tall on screen

const FONT_FILE = '/usr/share/fonts/opentype/urw-base35/URWBookman-Demi.otf';
const TEXT = 'CANDY TOP';
const TEXT_BOX = [19.0, 2.6, 139.0, 17.4];    // x0, capTop, x1, baseline (sub-px)

const SKY = [[0.00, '#120A2E'], [0.30, '#2E1758'], [0.55, '#6E2A72'], [0.72, '#C2477A'], [0.84, '#F27A72'], [1.00, '#FFC08A']];
const SKY_HORIZON = 21.0;          // sky gradient reaches 1.0 here
const STAR = '#FFF6E0';
/** Four-point sparkles (plus shapes): x, y, arm length (sub-px). */
const SPARKLES = [[152.0, 9.0, 1.6], [3.0, 2.2, 1.2], [70.0, 1.0, 1.0]];
const STAR_SEED = 77;
const STAR_COUNT = 70;
const MOON = ['x' => 8.5, 'y' => 5.2, 'r' => 5.0, 'bite' => [3.6, -0.9], 'col' => '#FFF1C4', 'glow' => '#7E5AA8'];
const SHOOTING = [[154.0, 1.2], [140.0, 5.0]];  // head → tail (sub-px)

const FAR_HILLS = ['base' => 20.0, 'amp' => [2.2, 1.0], 'freq' => [0.055, 0.13], 'col' => '#3A1F5E', 'haze' => '#6A3A80'];
const MID_HILLS = ['base' => 23.0, 'amp' => [2.4, 1.1], 'freq' => [0.043, 0.11], 'col' => '#9C3570', 'dark' => '#5A1E52'];
const FRONT_HILLS = ['base' => 26.0, 'amp' => [2.2, 0.9], 'freq' => [0.037, 0.093], 'col' => '#5ED6A8', 'dark' => '#1F7A68', 'rim' => '#FFE0A8'];
const GUMDROPS = ['#FF5E8A', '#FFD84D', '#FFFFFF', '#B07CFF', '#FF8A3D'];

/** Lollipops: x, stick foot y offset into hill, radius (x sub-px), swirl colours. */
const LOLLIPOPS = [
    [5.0, 1.0, 4.4, ['#FF4F8B', '#FFF4F8']],
    [13.5, 1.0, 2.8, ['#7BE0FF', '#FFFFFF']],
    [150.0, 1.0, 4.6, ['#FFC93D', '#FF5E3A']],
    [142.5, 1.0, 2.6, ['#B07CFF', '#FFFFFF']],
];
const CANES = [[44.0, 1.1], [101.0, 1.0]];      // x, height-ish scale

const FILL_LEFT = ['#FFF7D6', '#FFD65A', '#FF9A3C', '#E8562E'];   // top → bottom
const FILL_RIGHT = ['#FFF0F6', '#FF9EC8', '#FF4F96', '#C21E6A'];
const OUTLINE = '#2A0C3A';
const TEXT_SPLIT = 88.0;           // 16-colour: x where butterscotch letters give way to strawberry
const SHADOW = '#1A0626';
const HILITE = '#FFFFFF';

const DESCRIPTION = 'Twilight candyland: CANDY TOP in a smooth Bookman serif (TTF, sextant-rendered), butterscotch-to-strawberry sunset fill with bevel highlight, gloss band, plum outline and cast shadow, over a painted dusk scene: violet-to-apricot sky, stars, crescent moon, shooting star, layered rose and mint candy hills with rim light, swirl lollipops, candy canes and gumdrops.';
const TAGS = ['twilight-candyland-hills', 'sextant-mosaic', 'ttf-serif-letters', 'painted-scene', 'sunset-gradient', 'starry-sky', 'crescent-moon', 'layered-hills', 'lollipop-trees', 'outline-and-shadow'];

const ANIM_FRAMES = 16;            // dusk falls + stars twinkle + gloss sweep; ~0.15 s/frame

// ================================================================ HELPERS

function hx(string $h): array
{
    return lkHex($h);
}

function ramp(array $stops, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    for ($i = 1; $i < count($stops); $i++) {
        if ($t <= $stops[$i][0]) {
            [$t0, $c0] = $stops[$i - 1];
            [$t1, $c1] = $stops[$i];
            return lkMix(hx($c0), hx($c1), ($t - $t0) / max(1e-6, $t1 - $t0));
        }
    }
    return hx(end($stops)[1]);
}

function hash01(int $a, int $b = 0): float
{
    return hexdec(substr(md5("$a:$b:" . STAR_SEED), 0, 8)) / 0xFFFFFFFF;
}

/** Physical distance with sub-pixel aspect (y stretched). */
function pdist(float $x, float $y, float $cx, float $cy): float
{
    return hypot($x - $cx, ($y - $cy) * ASPECT);
}

function hill(array $spec, float $x, float $phase = 0.0): float
{
    return $spec['base'] - $spec['amp'][0] * sin($x * $spec['freq'][0] + 1.3 + $phase)
        - $spec['amp'][1] * sin($x * $spec['freq'][1] + 4.1 + $phase * 2);
}

// ---------------------------------------------------------------- text mask

/** Hi-res text coverage, sampled by sub-pixel coords. */
function textCov(float $x, float $y): float
{
    static $img = null, $S = 10.0, $w, $h;
    if ($img === null) {
        $w = (int) (PX_W * $S);
        $h = (int) (PX_H * $S * ASPECT);
        [$bx0, $cap, $bx1, $base] = TEXT_BOX;
        $size = 200.0;
        $hb = imagettfbbox($size, 0, FONT_FILE, 'H');
        $capPx = $hb[1] - $hb[7];
        $bb = imagettfbbox($size, 0, FONT_FILE, TEXT);
        $tw = $bb[2] - $bb[0];
        $tmp = imagecreatetruecolor($tw + 40, (int) ($capPx * 1.6));
        imagefill($tmp, 0, 0, 0);
        $white = imagecolorallocate($tmp, 255, 255, 255);
        $baseY = (int) ($capPx * 1.25);
        imagettftext($tmp, $size, 0, 20 - $bb[0], $baseY, $white, FONT_FILE, TEXT);
        // target rect in hi-res pixels: cap top → baseline mapped exactly
        $tCapTop = $cap * $S * ASPECT;
        $tBase = $base * $S * ASPECT;
        $k = ($tBase - $tCapTop) / $capPx;
        $srcY0 = $baseY - $capPx * 1.25;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, 0);
        imagecopyresampled(
            $img, $tmp,
            (int) ($bx0 * $S), (int) ($tBase - $baseY * $k), 0, 0,
            (int) (($bx1 - $bx0) * $S), (int) (imagesy($tmp) * $k),
            imagesx($tmp), imagesy($tmp),
        );
        unset($srcY0);
    }
    $ix = (int) ($x * $S);
    $iy = (int) ($y * $S * ASPECT);
    if ($ix < 0 || $iy < 0 || $ix >= $w || $iy >= $h) {
        return 0.0;
    }
    return (imagecolorat($img, $ix, $iy) & 0xFF) / 255;
}

function inText(float $x, float $y): bool
{
    return textCov($x, $y) >= 0.5;
}

// ---------------------------------------------------------------- scene

/**
 * @param float $dusk 0 = early twilight (warmer), 1 = final look
 * @param ?float $sweep gloss band x (anim) or null
 */
function scene(float $x, float $y, float $dusk = 1.0, ?float $sweep = null, int $twinkle = 0): array
{
    // ---- text + its edges (front-most)
    $t = textOrEdge($x, $y, $sweep);
    if ($t !== null) {
        return $t;
    }
    $c = background($x, $y, $dusk, $twinkle);
    // cast shadow of the text (down-right)
    if (inText($x - 1.4, $y - 1.1)) {
        $c = r16(lkMix($c, hx(SHADOW), 0.7), 30);
    }
    return $c;
}

function textOrEdge(float $x, float $y, ?float $sweep): ?array
{
    [$bx0, $cap, $bx1, $base] = TEXT_BOX;
    if ($x < $bx0 - 3 || $x > $bx1 + 3 || $y < $cap - 3 || $y > $base + 4) {
        return null;
    }
    if (inText($x, $y)) {
        $v = ($y - $cap) / ($base - $cap);                    // 0 top → 1 baseline
        $u = ($x - $bx0) / ($bx1 - $bx0);
        $stops = static fn (array $p): array => [[0.0, $p[0]], [0.3, $p[1]], [0.72, $p[2]], [1.0, $p[3]]];
        $c = lkMix(ramp($stops(FILL_LEFT), $v), ramp($stops(FILL_RIGHT), $v), smooth($u, 0.35, 0.7));
        if (is16()) {
            $right = $x > TEXT_SPLIT;
            $code = $v < 0.5 ? ($right ? 95 : 93) : ($right ? 35 : 33);
            if (!inText($x - 0.6, $y - 0.9) || ($sweep !== null && abs(($x - $sweep) + ($y - $cap) * 0.9) < 1.6)) {
                $code = 97;
            } elseif (!inText($x + 0.6, $y + 0.9)) {
                $code = $right ? 35 : 31;
            }
            return lkAnsi16()[$code];
        }
        // bevel: lit from above-left, shade underneath
        if (!inText($x - 0.6, $y - 0.9)) {
            $c = lkMix($c, hx(HILITE), 0.55);
        } elseif (!inText($x + 0.6, $y + 0.9)) {
            $c = lkScale($c, 0.72);
        }
        // gloss band across the upper third
        if ($v > 0.12 && $v < 0.3) {
            $c = lkMix($c, hx(HILITE), 0.22);
        }
        if ($sweep !== null) {
            $d = abs(($x - $sweep) + ($y - $cap) * 0.9);
            if ($d < 3.0) {
                $c = lkMix($c, hx(HILITE), 0.75 * (1 - $d / 3.0));
            }
        }
        return $c;
    }
    // outline ring: any text within ~1 sub-pixel physical radius
    foreach ([[1, 0], [-1, 0], [0, 0.8], [0, -0.8], [0.8, 0.6], [-0.8, 0.6], [0.8, -0.6], [-0.8, -0.6]] as [$dx, $dy]) {
        if (inText($x + $dx, $y + $dy)) {
            return r16(hx(OUTLINE), 30);
        }
    }
    return null;
}

/** 16-colour role render: true while painting the 16-colour image. */
function is16(): bool
{
    return $GLOBALS['R16'] ?? false;
}

/** Colour in normal mode, the given ANSI-16 code in role mode. */
function r16(array $c, int $code): array
{
    return is16() ? lkAnsi16()[$code] : $c;
}

/** Hue-preserving 16 code for an rgb (lollipops, gumdrops). */
function h16(array $c): int
{
    return lkTo16($c, [], 'hue');
}

function smooth(float $t, float $a, float $b): float
{
    $t = max(0.0, min(1.0, ($t - $a) / ($b - $a)));
    return $t * $t * (3 - 2 * $t);
}

function background(float $x, float $y, float $dusk, int $twinkle): array
{
    // ---- front hills (mint) + lollipops + canes + gumdrops
    $front = hill(FRONT_HILLS, $x);
    foreach (LOLLIPOPS as $i => [$lx, $foot, $r, $cols]) {
        $ground = hill(FRONT_HILLS, $lx) + $foot;
        $cy = $ground - 4.5 - $r * 1.1;
        $d = pdist($x, $y, $lx, $cy);
        if ($d <= $r) {
            $ang = atan2(($y - $cy) * ASPECT, $x - $lx);
            $sw = fmod($ang / (2 * M_PI) + $d / $r * 1.25 + 10, 1.0);   // spiral
            $c = hx($cols[$sw < 0.5 ? 0 : 1]);
            if (is16()) {
                return lkAnsi16()[h16($c)];
            }
            $c = lkShade($c, 0.25 - 0.5 * (($y - $cy) * ASPECT + ($x - $lx)) / (2 * $r));
            if (pdist($x, $y, $lx - $r * 0.4, $cy - $r * 0.4) < $r * 0.28) {
                $c = lkMix($c, [255, 255, 255], 0.7);
            }
            return $c;
        }
        if (abs($x - $lx) < 0.45 && $y > $cy && $y < $ground) {
            return r16(hx('#F4EBDD'), 37);
        }
    }
    foreach (CANES as [$cx, $sc]) {
        $ground = hill(FRONT_HILLS, $cx) + 1.0;
        $top = $ground - 5.0 * $sc;
        $hookR = 1.6;
        $onStick = abs($x - $cx) < 0.6 && $y > $top && $y < $ground;
        $dd = pdist($x, $y, $cx - $hookR, $top);
        $onHook = $y <= $top && abs($dd - $hookR) < 0.6;
        if ($onStick || $onHook) {
            return (((int) floor($x * 0.7 + $y * 0.9)) % 2 === 0) ? r16(hx('#FF2E4D'), 91) : r16(hx('#FFF6F6'), 97);
        }
    }
    if ($y >= $front) {
        $depth = ($y - $front);
        $c = lkMix(hx(FRONT_HILLS['col']), hx(FRONT_HILLS['dark']), min(1.0, $depth / 4.5));
        if ($depth < 0.9) {
            $c = lkMix($c, hx(FRONT_HILLS['rim']), 0.65);
        }
        $gi = (int) floor($x / 3.0);
        if (hash01($gi, 7) < 0.4) {
            $gx = $gi * 3.0 + 1.5;
            $gy = hill(FRONT_HILLS, $gx) + 1.6 + hash01($gi, 8) * 2.2;
            if (pdist($x, $y, $gx, $gy) < 1.05 && $y < $gy + 0.4) {
                $c = hx(GUMDROPS[(int) (hash01($gi, 9) * count(GUMDROPS))]);
                if (is16()) {
                    return lkAnsi16()[h16($c)];
                }
                if ($y < $gy - 0.3 && $x < $gx) {
                    $c = lkMix($c, [255, 255, 255], 0.5);
                }
            }
        }
        return r16($c, $depth < 0.9 ? 92 : 32);
    }
    // ---- mid hills (rose)
    $mid = hill(MID_HILLS, $x);
    if ($y >= $mid) {
        $c = lkMix(hx(MID_HILLS['col']), hx(MID_HILLS['dark']), min(1.0, ($y - $mid) / 3.0));
        if ($y - $mid < 0.8) {
            $c = lkMix($c, hx('#FFC7A0'), 0.45);
        }
        return r16($c, $y - $mid < 0.8 ? 95 : 35);
    }
    // ---- far hills (hazy violet)
    $far = hill(FAR_HILLS, $x);
    if ($y >= $far) {
        $c = lkMix(hx(FAR_HILLS['haze']), hx(FAR_HILLS['col']), min(1.0, ($y - $far) / 2.5));
        return r16($y - $far < 0.7 ? lkMix($c, hx('#E88AA0'), 0.4) : $c, $y - $far < 0.7 ? 94 : 34);
    }
    // ---- sky
    $st = $y / SKY_HORIZON - (1 - $dusk) * 0.25;
    $sky = ramp(SKY, $st);
    if (is16()) {
        $sky = lkAnsi16()[$st < 0.42 ? 30 : ($st < 0.72 ? 34 : 35)];
    }
    // moon glow + crescent
    $m = MOON;
    $dm = pdist($x, $y, $m['x'], $m['y']);
    if ($dm <= $m['r']) {
        $bite = pdist($x, $y, $m['x'] + $m['bite'][0], $m['y'] + $m['bite'][1]);
        if ($bite > $m['r'] * 0.8) {
            $c = hx($m['col']);
            return r16(lkShade($c, -0.15 * ($dm / $m['r'])), 93);
        }
    }
    if ($dm < $m['r'] * 2.4 && !is16()) {
        $sky = lkMix($sky, hx($m['glow']), 0.45 * (1 - $dm / ($m['r'] * 2.4)));
    }
    // shooting star
    [[$hx0, $hy0], [$tx, $ty]] = SHOOTING;
    $len = hypot($hx0 - $tx, $hy0 - $ty);
    $ux = ($hx0 - $tx) / $len;
    $uy = ($hy0 - $ty) / $len;
    $along = ($x - $tx) * $ux + ($y - $ty) * $uy;
    $perp = abs(-($x - $tx) * $uy + ($y - $ty) * $ux);
    if ($along > 0 && $along < $len && $perp < 0.75) {
        $sky = r16(lkMix($sky, hx(STAR), 0.25 + 0.75 * $along / $len), $along / $len > 0.45 ? 97 : 37);
    }
    foreach (SPARKLES as $k => [$sx0, $sy0, $arm]) {
        $ax = abs($x - $sx0);
        $ay = abs($y - $sy0) * ASPECT;
        if (($ax < 0.5 && $ay < $arm * ASPECT * 0.9) || ($ay < 0.6 && $ax < $arm + 0.5)) {
            $on = $twinkle === 0 || ($twinkle + $k) % 3 !== 0;
            if ($on) {
                return r16(hx(STAR), 97);
            }
        }
    }
    // stars (only above the glow band)
    $starLimit = SKY_HORIZON * 0.62;
    if ($y < $starLimit) {
        $sx = (int) floor($x);
        $sy = (int) floor($y);
        for ($i = 0; $i < STAR_COUNT; $i++) {
            $px = hash01($i, 1) * PX_W;
            $py = hash01($i, 2) * $starLimit;
            if ($sx === (int) floor($px) && $sy === (int) floor($py)) {
                $b = 0.45 + 0.55 * hash01($i, 3);
                if ($twinkle > 0) {
                    $b *= 0.35 + 0.65 * abs(sin($twinkle * 0.9 + $i));
                }
                $b *= 0.4 + 0.6 * $dusk;
                return r16(lkMix($sky, hx(STAR), $b), $b > 0.55 ? 97 : 37);
            }
        }
    }
    return $sky;
}

// ================================================================ 16-colour map

/** Role-free nearest over a curated subset (keeps sky violet/blue, hills green/magenta). */
function map16(array $c): array
{
    return lkAnsi16()[lkTo16($c, [], 'hue')];
}

// ================================================================ BUILD

function image(float $dusk = 1.0, ?float $sweep = null, int $twinkle = 0, bool $role16 = false): array
{
    $GLOBALS['R16'] = $role16;
    $img = siSupersample(PX_W, PX_H, static fn (float $x, float $y) => scene($x, $y, $dusk, $sweep, $twinkle), $role16 ? 1 : 3);
    $GLOBALS['R16'] = false;
    return $img;
}

function render(array $img, string $depth): string
{
    $q = $depth === '16' ? $img : siQuantise($img, $depth, 0.0);
    return lkEncode(siCells($q, $depth !== 'tc'), $depth, [], 'nearest');
}

$write = in_array('--write', $argv, true);
$png = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--png=')) {
        $png = substr($a, 6);
    }
}
$dir = dirname(__DIR__);
$noAnim = in_array('--no-anim', $argv, true) || !$write;
foreach (['tc', '256', '16'] as $depth) {
    $role = $depth === '16';
    $img = image(1.0, null, 0, $role);
    if ($png !== null) {
        siImagePng($img, "$png/subpixels-$depth.png");
    }
    $static = render($img, $depth);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static, "\n";
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if (!$write) {
        continue;
    }
    $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
    fwrite(STDERR, "  wrote {$r['file']}\n");
    if ($noAnim) {
        continue;
    }
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $p = $f / (ANIM_FRAMES - 1);
        $dusk = min(1.0, 0.25 + $p * 1.2);
        $sweep = $p > 0.45 ? TEXT_BOX[0] - 20 + (TEXT_BOX[2] - TEXT_BOX[0] + 40) * ($p - 0.45) / 0.55 : null;
        $frames[] = render(image($dusk, $sweep, $f + 1, $role), $depth);
    }
    $anim = lkAnim($frames, $static);
    $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
        'Animated ' . DESCRIPTION . ' Dusk deepens, stars twinkle on, then a gloss glint sweeps the lettering; ' . (count($frames) + 1) . " frames, play with tools/logo-play.php <file> 150 (~2.5 s) ($depth)",
        [...TAGS, 'animated', 'dusk-fall', 'star-twinkle', 'gloss-sweep']);
    fwrite(STDERR, "  wrote {$r['file']}\n");
}
