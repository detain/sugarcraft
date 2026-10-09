<?php

declare(strict_types=1);

/**
 * skinny-raster-kit — shared helpers for SKINNY (≤ 40-col) variants of the candy-top
 * logos. Library only (require it); the CLIs built on it are:
 *   skinny-seamcarve.php  any .ansi → content-aware condense (seam carving +
 *                         area downscale) re-rastered at a narrower grid
 *   skinny-b-compose.php  cell-exact crop / paste / rescale (uses skinny-b-kit.php)
 *   skinny-compare.php    original-over-skinny PNG contact sheet
 *   skinny-sheet.php      any-files PNG contact sheet
 *   skinny-b-check.php    verify skinny files
 *   (all reachable via skinny.php; require skinny-kit.php to load every library)
 *
 * Pipeline pieces
 *   skParse($ansi)            ANSI text (last frame) → cell grid [glyph, fg, bg]
 *   skRender($cells, …)       cell grid → GD image (exact sextants, quadrants,
 *                             half/eighth blocks, ░▒▓ stipple, braille dots,
 *                             ◢◣◤◥ wedges, DejaVu TTF for everything else),
 *                             transparent where bg is null and nothing is inked
 *   skSample($gd, pw, ph)     area-average a GD image down to a pw×ph
 *                             sub-pixel grid of rgb|null (alpha-aware)
 *   skRaster($img, $mode, $depth, $mode16, $dither)
 *                             sub-pixel grid → cells at sextant (2×3),
 *                             quadrant (2×2) or half (1×2) resolution; for
 *                             256/16 the sub-pixels are quantised FIRST so each
 *                             cell's two colours are real palette entries
 *   skTone($img, gain, sat, gamma)  brighten / saturate after averaging
 *   skPng($cells, $out)       quick PNG of a cell grid
 *   skWriteSkinny(...)        verify (equal widths, \e[0m, ≤ maxW, depth purity),
 *                             write logo-<slug>-<depth>-WxH.ansi and APPEND one
 *                             logos.jsonl line with a single O_APPEND write
 *                             (never rewrites the shared file; skips a line
 *                             whose filename is already present)
 *
 * A cell is [glyph, fg rgb|null, bg rgb|null] exactly as in logo-kit.php; a
 * wide glyph is followed by a '' placeholder cell.
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-canvas.php';
require_once __DIR__ . '/sextant-image-raster.php';

const SK_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
const SK_QUAD = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];
const SK_HALF = [' ', '▀', '▄', '█'];

// ================================================================ parse

/** ANSI text → cell rows. Uses the LAST frame of an animation. */
function skParse(string $ansi): array
{
    $ansi = lkLastFrame($ansi);
    $x256 = lkXterm256();
    $x16 = lkAnsi16();
    $rows = [];
    foreach (explode("\n", rtrim($ansi, "\n")) as $line) {
        $fg = $bg = null;
        $row = [];
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = $bg = null;
                    } elseif ($v === 39) {
                        $fg = null;
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 2) {
                            $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                            $i += 4;
                        } else {
                            $c = $x256[$p[$i + 2]];
                            $i += 2;
                        }
                        $v === 38 ? $fg = $c : $bg = $c;
                    } elseif (($v >= 30 && $v <= 37) || ($v >= 90 && $v <= 97)) {
                        $fg = $x16[$v];
                    } elseif (($v >= 40 && $v <= 47) || ($v >= 100 && $v <= 107)) {
                        $bg = $x16[$v - 10];
                    }
                }
                continue;
            }
            foreach (mb_str_split(preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $tok)) as $g) {
                $row[] = [$g, $fg, $bg];
                if (mb_strwidth($g, 'UTF-8') === 2) {
                    $row[] = ['', $fg, $bg];
                }
            }
        }
        $rows[] = $row;
    }
    $w = max(array_map('count', $rows));
    foreach ($rows as &$r) {
        while (count($r) < $w) {
            $r[] = [' ', null, null];
        }
    }
    return $rows;
}

function skLoad(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("no such file: $path");
    }
    return skParse(file_get_contents($path));
}

// ================================================================ render

/**
 * Cell grid → GD truecolour image with alpha. Unpainted area is fully
 * transparent unless $page (rgb) is given.
 */
function skRender(array $cells, int $cw = 12, int $ch = 24, ?array $page = null, string $font = SK_FONT): GdImage
{
    $W = max(array_map('count', $cells));
    $im = imagecreatetruecolor($W * $cw, count($cells) * $ch);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $page ? imagecolorallocatealpha($im, ...[...$page, 0]) : imagecolorallocatealpha($im, 0, 0, 0, 127));
    $col = static fn (array $c): int => imagecolorallocatealpha($im, $c[0], $c[1], $c[2], 0);
    $rect = static function (int $x0, int $y0, int $x1, int $y1, int $c) use ($im): void {
        if ($x1 >= $x0 && $y1 >= $y0) {
            imagefilledrectangle($im, $x0, $y0, $x1, $y1, $c);
        }
    };
    $shade = ['░' => 0.25, '▒' => 0.5, '▓' => 0.75];
    $eighthLow = ['▁' => 1, '▂' => 2, '▃' => 3, '▄' => 4, '▅' => 5, '▆' => 6, '▇' => 7, '█' => 8];
    $eighthLeft = ['▏' => 1, '▎' => 2, '▍' => 3, '▌' => 4, '▋' => 5, '▊' => 6, '▉' => 7];
    $quad = array_flip(SK_QUAD);
    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            if ($g === '') {
                continue;
            }
            $x0 = $x * $cw;
            $y0 = $y * $ch;
            $x1 = $x0 + $cw - 1;
            $y1 = $y0 + $ch - 1;
            if ($bg !== null) {
                $rect($x0, $y0, $x1, $y1, $col($bg));
            }
            if ($g === ' ' || $g === "\u{2800}") {
                continue;
            }
            $c = $col($fg ?? [220, 220, 220]);
            $hw = intdiv($cw, 2);
            $hh = intdiv($ch, 2);
            if (($bits = scBits($g)) !== null) {
                for ($b = 0; $b < 6; $b++) {
                    if ($bits & (1 << $b)) {
                        $sx = $x0 + ($b % 2) * $hw;
                        $r = intdiv($b, 2);
                        $sy = $y0 + intdiv($r * $ch, 3);
                        $ey = $y0 + intdiv(($r + 1) * $ch, 3) - 1;
                        $rect($sx, $sy, $b % 2 ? $x1 : $sx + $hw - 1, $ey, $c);
                    }
                }
            } elseif (isset($quad[$g]) && $g !== ' ') {
                $bits = $quad[$g];
                foreach ([[1, 0, 0], [2, 1, 0], [4, 0, 1], [8, 1, 1]] as [$bit, $qx, $qy]) {
                    if ($bits & $bit) {
                        $rect($x0 + $qx * $hw, $y0 + $qy * $hh, $qx ? $x1 : $x0 + $hw - 1, $qy ? $y1 : $y0 + $hh - 1, $c);
                    }
                }
            } elseif ($g === '▀') {
                $rect($x0, $y0, $x1, $y0 + $hh - 1, $c);
            } elseif (isset($eighthLow[$g])) {
                $rect($x0, $y1 - intdiv($eighthLow[$g] * $ch, 8) + 1, $x1, $y1, $c);
            } elseif (isset($eighthLeft[$g])) {
                $rect($x0, $y0, $x0 + intdiv($eighthLeft[$g] * $cw, 8) - 1, $y1, $c);
            } elseif ($g === '▐') {
                $rect($x0 + $hw, $y0, $x1, $y1, $c);
            } elseif ($g === '▔') {
                $rect($x0, $y0, $x1, $y0 + intdiv($ch, 8) - 1, $c);
            } elseif ($g === '▕') {
                $rect($x1 - intdiv($cw, 8) + 1, $y0, $x1, $y1, $c);
            } elseif (isset($shade[$g])) {
                $d = $shade[$g];
                $bayer = [[0, 2], [3, 1]];
                for ($py = 0; $py < $ch; $py += 2) {
                    for ($px = 0; $px < $cw; $px += 2) {
                        if (($bayer[($py / 2) & 1][($px / 2) & 1] + 0.5) / 4 < $d) {
                            $rect($x0 + $px, $y0 + $py, $x0 + $px + 1, $y0 + $py + 1, $c);
                        }
                    }
                }
            } elseif (in_array($g, ['◢', '◣', '◤', '◥'], true)) {
                $pts = match ($g) {
                    '◢' => [$x1, $y0, $x1, $y1, $x0, $y1],
                    '◣' => [$x0, $y0, $x0, $y1, $x1, $y1],
                    '◤' => [$x0, $y0, $x1, $y0, $x0, $y1],
                    '◥' => [$x0, $y0, $x1, $y0, $x1, $y1],
                };
                imagefilledpolygon($im, $pts, $c);
            } elseif (($o = mb_ord($g, 'UTF-8')) >= 0x2800 && $o <= 0x28FF) {
                $bb = $o - 0x2800;
                $map = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];
                $r = max(1, intdiv($cw, 5));
                foreach ($map as $dy => $pair) {
                    foreach ($pair as $dx => $bit) {
                        if ($bb & $bit) {
                            imagefilledellipse($im, $x0 + (int) (($dx + 0.5) * $cw / 2), $y0 + (int) (($dy + 0.5) * $ch / 4), 2 * $r, 2 * $r, $c);
                        }
                    }
                }
            } else {
                imagealphablending($im, true);
                $wide = isset($row[$x + 1]) && $row[$x + 1][0] === '';
                $size = $ch * 0.62;
                $bbx = imagettfbbox($size, 0, $font, $g);
                $gw = $bbx[2] - $bbx[0];
                $span = $wide ? 2 * $cw : $cw;
                imagettftext($im, $size, 0, $x0 + (int) (($span - $gw) / 2) - $bbx[0], $y0 + (int) ($ch * 0.78), $c, $font, $g);
                imagealphablending($im, false);
            }
        }
    }
    return $im;
}

/** Write a cell grid (or an .ansi file's cells) to PNG on a page colour. */
function skPng(array $cells, string $out, string $page = '#000000', int $cw = 12, int $ch = 24): void
{
    $im = skRender($cells, $cw, $ch, lkHex($page));
    imagepng($im, $out);
}

// ================================================================ resample

/**
 * Area-average a GD image (with alpha) down to a pw×ph sub-pixel grid.
 * A sub-pixel whose opaque coverage is below $minCover becomes null
 * (transparent). Optional $src crop box [x0, y0, x1, y1) in image pixels.
 * @return list<list<?array>>
 */
function skSample(GdImage $im, int $pw, int $ph, ?array $src = null, float $minCover = 0.5): array
{
    [$sx0, $sy0, $sx1, $sy1] = $src ?? [0, 0, imagesx($im), imagesy($im)];
    $fx = ($sx1 - $sx0) / $pw;
    $fy = ($sy1 - $sy0) / $ph;
    $img = [];
    for ($y = 0; $y < $ph; $y++) {
        $ya = $sy0 + $y * $fy;
        $yb = $ya + $fy;
        for ($x = 0; $x < $pw; $x++) {
            $xa = $sx0 + $x * $fx;
            $xb = $xa + $fx;
            $acc = [0.0, 0.0, 0.0];
            $wa = 0.0;
            $wt = 0.0;
            for ($py = (int) floor($ya); $py < (int) ceil($yb); $py++) {
                $wy = min($yb, $py + 1) - max($ya, $py);
                if ($wy <= 0) {
                    continue;
                }
                for ($px = (int) floor($xa); $px < (int) ceil($xb); $px++) {
                    $wx = min($xb, $px + 1) - max($xa, $px);
                    if ($wx <= 0) {
                        continue;
                    }
                    $w = $wx * $wy;
                    $wt += $w;
                    $rgba = imagecolorat($im, min($px, imagesx($im) - 1), min($py, imagesy($im) - 1));
                    $a = 1 - (($rgba >> 24) & 0x7F) / 127;
                    if ($a <= 0) {
                        continue;
                    }
                    $w *= $a;
                    $wa += $w;
                    $acc[0] += (($rgba >> 16) & 0xFF) * $w;
                    $acc[1] += (($rgba >> 8) & 0xFF) * $w;
                    $acc[2] += ($rgba & 0xFF) * $w;
                }
            }
            $img[$y][$x] = ($wt == 0.0 || $wa / $wt < $minCover) ? null
                : [(int) round($acc[0] / $wa), (int) round($acc[1] / $wa), (int) round($acc[2] / $wa)];
        }
    }
    return $img;
}

/**
 * Tone a sub-pixel grid: gain (brightness ×), sat (saturation ×, about the
 * pixel's luma), gamma (<1 lifts mids). Downscaling averages ink with the
 * black around it, so a little gain/sat restores the original's punch.
 */
function skTone(array $img, float $gain = 1.0, float $sat = 1.0, float $gamma = 1.0): array
{
    if ($gain == 1.0 && $sat == 1.0 && $gamma == 1.0) {
        return $img;
    }
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c === null) {
                continue;
            }
            $l = 0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2];
            $o = [];
            foreach ($c as $v) {
                $v = $l + ($v - $l) * $sat;
                $v = 255 * (max(0.0, $v) / 255) ** $gamma * $gain;
                $o[] = max(0, min(255, (int) round($v)));
            }
            $img[$y][$x] = $o;
        }
    }
    return $img;
}

// ================================================================ raster

/** Sub-pixel size per cell for a raster mode. @return array{0:int,1:int} */
function skSub(string $mode): array
{
    return match ($mode) {
        'sextant' => [2, 3],
        'quadrant' => [2, 2],
        'half' => [1, 2],
        default => throw new InvalidArgumentException("raster mode $mode (sextant|quadrant|half)"),
    };
}

/** 16-colour sub-pixel picker for siQuantise: 'hue' (pastels keep hue) or 'nearest'. */
function skMap16(string $mode16, array $pins = []): callable
{
    $a16 = lkAnsi16();
    return static fn (array $c): array => $a16[lkTo16($c, $pins, $mode16)];
}

/**
 * Sub-pixel grid → cell grid. Sextant uses sextant-image-raster's exhaustive
 * 2-colour fit; quadrant/half use the same fit over 2×2 / 1×2 partitions.
 * For depth 256/16 the image is quantised first and colours are snapped.
 */
function skRaster(array $img, string $mode = 'sextant', string $depth = 'tc', string $mode16 = 'hue', float $dither = 0.0, array $pins16 = []): array
{
    $img = siQuantise($img, $depth, $dither, $depth === '16' ? skMap16($mode16, $pins16) : null);
    $snap = $depth !== 'tc';
    if ($mode === 'sextant') {
        return siCells($img, $snap);
    }
    [$sw, $sh] = skSub($mode);
    $glyphs = $mode === 'quadrant' ? SK_QUAD : SK_HALF;
    $n = $sw * $sh;
    $cells = [];
    $ph = count($img);
    $pw = count($img[0]);
    for ($cy = 0; $cy * $sh < $ph; $cy++) {
        for ($cx = 0; $cx * $sw < $pw; $cx++) {
            $sub = [];
            for ($k = 0; $k < $n; $k++) {
                $sub[$k] = $img[$cy * $sh + intdiv($k, $sw)][$cx * $sw + $k % $sw] ?? null;
            }
            $cells[$cy][] = skFit($sub, $glyphs, $snap);
        }
    }
    return $cells;
}

/** Generic best-partition 2-colour fit; $glyphs indexed by fg bitmask. */
function skFit(array $sub, array $glyphs, bool $snap): array
{
    $n = count($sub);
    $full = (1 << $n) - 1;
    $opaque = 0;
    foreach ($sub as $k => $c) {
        if ($c !== null) {
            $opaque |= 1 << $k;
        }
    }
    if ($opaque === 0) {
        return [' ', null, null];
    }
    if ($opaque !== $full) {
        return [$glyphs[$opaque], siMean(array_filter($sub), $snap), null];
    }
    $best = null;
    $bestErr = INF;
    for ($mask = 0; $mask < (1 << ($n - 1)); $mask++) {
        $a = $b = [];
        foreach ($sub as $k => $c) {
            ($mask & (1 << $k)) ? $a[] = $c : $b[] = $c;
        }
        $ma = $a ? siMean($a, $snap) : null;
        $mb = siMean($b, $snap);
        $err = 0.0;
        foreach ($a as $c) {
            $err += siErr($c, $ma);
        }
        foreach ($b as $c) {
            $err += siErr($c, $mb);
        }
        if ($err < $bestErr - 1e-6) {
            $bestErr = $err;
            $best = [$mask, $ma, $mb];
        }
    }
    [$mask, $fg, $bg] = $best;
    return $mask === 0 ? [' ', null, $bg] : [$glyphs[$mask], $fg, $bg];
}

// ================================================================ cell ops

/** Inclusive "a-b" / "a" spec list → sorted int set. "a-" = to $max. */
function skRange(string $spec, int $max): array
{
    $out = [];
    foreach (array_filter(explode(',', $spec), 'strlen') as $part) {
        if (preg_match('/^(\d*)-(\d*)$/', $part, $m)) {
            $a = $m[1] === '' ? 0 : (int) $m[1];
            $b = $m[2] === '' ? $max : (int) $m[2];
            for ($i = $a; $i <= min($b, $max); $i++) {
                $out[$i] = $i;
            }
        } else {
            $out[(int) $part] = (int) $part;
        }
    }
    ksort($out);
    return array_values($out);
}

function skWidth(array $cells): int
{
    return count($cells[0] ?? []);
}

/** Keep columns x0..x1 and rows y0..y1 (inclusive). */
function skCrop(array $cells, int $x0, int $x1, int $y0 = 0, ?int $y1 = null): array
{
    $y1 ??= count($cells) - 1;
    $out = [];
    for ($y = $y0; $y <= $y1; $y++) {
        $out[] = array_slice($cells[$y], $x0, $x1 - $x0 + 1);
    }
    return skFixWide($out);
}

/** Remove the listed columns. */
function skDropCols(array $cells, array $cols): array
{
    $drop = array_flip($cols);
    foreach ($cells as $y => $row) {
        $cells[$y] = array_values(array_filter($row, static fn ($k) => !isset($drop[$k]), ARRAY_FILTER_USE_KEY));
    }
    return skFixWide($cells);
}

function skDropRows(array $cells, array $rows): array
{
    $drop = array_flip($rows);
    return array_values(array_filter($cells, static fn ($k) => !isset($drop[$k]), ARRAY_FILTER_USE_KEY));
}

/** A wide glyph that lost its '' partner (or an orphan '') becomes a space. */
function skFixWide(array $cells): array
{
    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            if ($g === '' && (($row[$x - 1][0] ?? ' ') === '' || mb_strwidth($row[$x - 1][0] ?? ' ') !== 2)) {
                $cells[$y][$x] = [' ', null, $bg];
            } elseif ($g !== '' && mb_strwidth($g) === 2 && ($row[$x + 1][0] ?? null) !== '') {
                $cells[$y][$x] = [' ', null, $bg];
            }
        }
    }
    return $cells;
}

/** Pad every row to $w, $align = left|center|right, with [' ', null, $bg]. */
function skPad(array $cells, int $w, string $align = 'center', ?array $bg = null): array
{
    foreach ($cells as $y => $row) {
        $d = $w - count($row);
        if ($d <= 0) {
            continue;
        }
        $l = match ($align) {
            'left' => 0,
            'right' => $d,
            default => intdiv($d, 2),
        };
        $cells[$y] = [...array_fill(0, $l, [' ', null, $bg]), ...$row, ...array_fill(0, $d - $l, [' ', null, $bg])];
    }
    return $cells;
}

/** Vertically stack grids (each padded to the widest, $align). */
function skStack(array $grids, string $align = 'center', ?array $bg = null): array
{
    $w = max(array_map('skWidth', $grids));
    $out = [];
    foreach ($grids as $g) {
        foreach (skPad($g, $w, $align, $bg) as $row) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Paste $top onto $base at (dx, dy). A cell of $top is transparent (base
 * shows through) when it is a space with null bg — or, with $keyBlack, a
 * space whose bg is pure black.
 */
function skOverlay(array $base, array $top, int $dx, int $dy, bool $keyBlack = false): array
{
    foreach ($top as $y => $row) {
        foreach ($row as $x => $cell) {
            [$g, , $bg] = $cell;
            if ($g === ' ' && ($bg === null || ($keyBlack && $bg === [0, 0, 0]))) {
                continue;
            }
            if (isset($base[$y + $dy][$x + $dx])) {
                $base[$y + $dy][$x + $dx] = $cell;
            }
        }
    }
    return skFixWide($base);
}

/** Replace null backgrounds with $bg (e.g. black) everywhere. */
function skFillBg(array $cells, array $bg): array
{
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c[2] === null && $c[0] !== '') {
                $cells[$y][$x][2] = $bg;
            }
        }
    }
    return $cells;
}

// ================================================================ write

/**
 * Verify + write logo-<slug>-<depth>-WxH.ansi and append its logos.jsonl line.
 * The jsonl append is ONE file_put_contents(FILE_APPEND|LOCK_EX) call — the
 * same O_APPEND semantics as a shell `>>`, safe alongside other agents. A line
 * whose filename already exists is NOT duplicated (the shared file is never
 * rewritten); pass $jsonl=false to skip it entirely.
 * @return array{file:string,width:int,height:int,jsonl:string}
 */
function skWriteSkinny(string $dir, string $slug, string $depth, string $ansi, string $description, array $tags, bool $jsonl = true, int $maxW = 40, array $hRange = [5, 15]): array
{
    [$w, $h] = lkVerify(lkLastFrame($ansi), "$slug/$depth");
    lkCheckDepth($ansi, $depth, "$slug/$depth");
    if ($w > $maxW) {
        throw new RuntimeException("$slug/$depth: width $w > $maxW");
    }
    if ($h < $hRange[0] || $h > $hRange[1]) {
        throw new RuntimeException("$slug/$depth: height $h outside {$hRange[0]}-{$hRange[1]}");
    }
    $name = "logo-$slug-$depth-{$w}x{$h}.ansi";
    file_put_contents("$dir/$name", $ansi);
    $status = 'off';
    if ($jsonl) {
        $path = "$dir/logos.jsonl";
        $exists = false;
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) as $l) {
                if (($l !== '') && ((json_decode($l, true)['filename'] ?? null) === $name)) {
                    $exists = true;
                    break;
                }
            }
        }
        if ($exists) {
            $status = 'already present (not duplicated)';
        } else {
            $line = json_encode([
                'filename' => $name, 'slug' => $slug, 'colors' => $depth, 'width' => $w, 'height' => $h,
                'description' => $description, 'tags' => array_values($tags),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
            $status = 'appended';
        }
    }
    return ['file' => $name, 'width' => $w, 'height' => $h, 'jsonl' => $status];
}

/** Parse --key=value / --flag CLI options. */
function skOpts(array $argv): array
{
    $o = ['_' => []];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $a, $m)) {
            $k = $m[1];
            $v = $m[2] ?? true;
            if (isset($o[$k])) {
                $o[$k] = [...(array) $o[$k], $v];
            } else {
                $o[$k] = $v;
            }
        } else {
            $o['_'][] = $a;
        }
    }
    return $o;
}
