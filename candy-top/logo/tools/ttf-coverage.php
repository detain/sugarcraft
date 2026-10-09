<?php

declare(strict_types=1);

/**
 * ttf-coverage — rasterise any TTF/OTF string into an anti-aliased coverage
 * grid at ANY sub-cell resolution (half-block 1×2, quadrant 2×2, sextant
 * 2×3 …), aspect-correct for terminal cells (a cell is 1 unit wide, 2 tall).
 *
 *   $r = tcRender($font, 'candy top', subW: 2, subH: 3, gridW: 152, gridH: 27,
 *                 box: [x0, y0, x1, y1],   // target sub-pixel box for the INK bounds
 *                 opts: ['maxCondense' => 0.6, 'tracking' => 0.0, 'ss' => 4]);
 *   $r['cov'][y][x]   0..1 coverage
 *   $r['letter'][y][x] index of the letter (non-space chars) owning that pixel, -1 none
 *   $r['u'][y][x], $r['v'][y][x]  0..1 position inside the ink box (for gradients)
 *   $r['box']  actual sub-pixel box used [x0,y0,x1,y1]
 *
 * The ink box is fitted to the box HEIGHT; if the result is wider than the
 * box, the text is condensed horizontally (down to maxCondense) and then, if
 * still too wide, scaled down uniformly and centred.
 *
 * Tracking (extra space between letters, in font px at render size 200) is
 * applied by drawing glyph by glyph using prefix-string advances, so kerning
 * inside the string is kept.
 */

function tcRender(string $font, string $text, int $subW, int $subH, int $gridW, int $gridH, array $box, array $opts = []): array
{
    $size = 200.0;
    $tracking = (float) ($opts['tracking'] ?? 0.0);
    $ss = (int) ($opts['ss'] ?? 4);
    $maxCondense = (float) ($opts['maxCondense'] ?? 0.6);
    $chars = mb_str_split($text);
    // Glyph origins from prefix advances (+ tracking per letter).
    $origins = [];
    foreach ($chars as $i => $ch) {
        $pre = implode('', array_slice($chars, 0, $i));
        $adv = $pre === '' ? 0 : imagettfbbox($size, 0, $font, $pre . 'l')[2] - imagettfbbox($size, 0, $font, 'l')[2];
        $origins[] = $adv + $tracking * $i;
    }
    $full = imagettfbbox($size, 0, $font, $text);
    $iw = (int) ($full[2] - $full[0] + $tracking * count($chars) + $size * 1.5);
    $ih = (int) ($size * 2.2);
    $base = (int) ($size * 1.5);
    $pad = (int) ($size * 0.5);
    // One image per letter so ownership is exact even where glyphs overlap.
    $imgs = [];
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            continue;
        }
        $im = imagecreatetruecolor($iw, $ih);
        imagefill($im, 0, 0, 0);
        imagettftext($im, $size, 0, (int) round($pad + $origins[$i]), $base, 0xFFFFFF, $font, $ch);
        $imgs[$i] = $im;
    }
    // Ink bounds over all letters.
    $bx0 = $iw;
    $by0 = $ih;
    $bx1 = 0;
    $by1 = 0;
    foreach ($imgs as $im) {
        for ($y = 0; $y < $ih; $y += 2) {
            for ($x = 0; $x < $iw; $x += 2) {
                if ((imagecolorat($im, $x, $y) & 0xFF) > 60) {
                    $bx0 = min($bx0, $x);
                    $bx1 = max($bx1, $x + 2);
                    $by0 = min($by0, $y);
                    $by1 = max($by1, $y + 2);
                }
            }
        }
    }
    // Units: sub-pixel = (1/subW) wide, (2/subH) tall.
    $uw = 1.0 / $subW;
    $uh = 2.0 / $subH;
    [$X0, $Y0, $X1, $Y1] = $box;
    $boxWu = ($X1 - $X0) * $uw;
    $boxHu = ($Y1 - $Y0) * $uh;
    $ky = $boxHu / ($by1 - $by0);             // units per image px (vertical)
    $kx = $ky;
    if (($bx1 - $bx0) * $kx > $boxWu) {
        $kx = max($ky * $maxCondense, $boxWu / ($bx1 - $bx0));
        if (($bx1 - $bx0) * $kx > $boxWu) {   // still too wide: shrink uniformly
            $f = $boxWu / (($bx1 - $bx0) * $kx);
            $kx *= $f;
            $ky *= $f;
        }
    }
    $inkWu = ($bx1 - $bx0) * $kx;
    $inkHu = ($by1 - $by0) * $ky;
    $ox = $X0 * $uw + ($boxWu - $inkWu) / 2;  // centred in the box
    $oy = $Y0 * $uh + ($boxHu - $inkHu) / 2;
    $cov = array_fill(0, $gridH, array_fill(0, $gridW, 0.0));
    $letter = array_fill(0, $gridH, array_fill(0, $gridW, -1));
    $u = $cov;
    $v = $cov;
    $li = 0;
    $letterIndex = [];
    foreach ($chars as $i => $ch) {
        if ($ch !== ' ') {
            $letterIndex[$i] = $li++;
        }
    }
    for ($py = 0; $py < $gridH; $py++) {
        for ($px = 0; $px < $gridW; $px++) {
            $best = 0.0;
            $owner = -1;
            $tot = 0.0;
            foreach ($imgs as $i => $im) {
                $sum = 0;
                for ($sy = 0; $sy < $ss; $sy++) {
                    for ($sx = 0; $sx < $ss; $sx++) {
                        $ux = ($px + ($sx + 0.5) / $ss) * $uw;
                        $uy = ($py + ($sy + 0.5) / $ss) * $uh;
                        $ix = (int) ($bx0 + ($ux - $ox) / $kx);
                        $iy = (int) ($by0 + ($uy - $oy) / $ky);
                        if ($ix >= 0 && $iy >= 0 && $ix < $iw && $iy < $ih) {
                            $sum += imagecolorat($im, $ix, $iy) & 0xFF;
                        }
                    }
                }
                $c = $sum / ($ss * $ss * 255);
                $tot = max($tot, $c);
                if ($c > $best) {
                    $best = $c;
                    $owner = $letterIndex[$i];
                }
            }
            $cov[$py][$px] = min(1.0, $tot);
            $letter[$py][$px] = $owner;
            $u[$py][$px] = (($px + 0.5) * $uw - $ox) / $inkWu;
            $v[$py][$px] = (($py + 0.5) * $uh - $oy) / $inkHu;
        }
    }
    foreach ($imgs as $im) {
        imagedestroy($im);
    }
    return [
        'cov' => $cov, 'letter' => $letter, 'u' => $u, 'v' => $v,
        'box' => [$ox / $uw, $oy / $uh, ($ox + $inkWu) / $uw, ($oy + $inkHu) / $uh],
        'condense' => $kx / $ky, 'letters' => $li,
    ];
}

/** Grow (r>0) or shrink (r<0) a coverage mask by r sub-pixels (circular, thresholded at 0.5). */
function tcDilate(array $cov, float $r, float $aspect = 1.0): array
{
    $h = count($cov);
    $w = count($cov[0]);
    $out = array_fill(0, $h, array_fill(0, $w, 0.0));
    $R = (int) ceil(abs($r));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $hit = $r > 0 ? false : true;
            for ($dy = -$R; $dy <= $R; $dy++) {
                for ($dx = -$R; $dx <= $R; $dx++) {
                    if ($dx * $dx + ($dy * $aspect) ** 2 > $r * $r) {
                        continue;
                    }
                    $on = ($cov[$y + $dy][$x + $dx] ?? 0.0) >= 0.5;
                    if ($r > 0 && $on) {
                        $hit = true;
                        break 2;
                    }
                    if ($r < 0 && !$on) {
                        $hit = false;
                        break 2;
                    }
                }
            }
            $out[$y][$x] = $hit ? 1.0 : 0.0;
        }
    }
    return $out;
}
