<?php

declare(strict_types=1);

/**
 * mixed-glyph-preview — render an .ansi logo (last frame of an animation) to
 * PNG for logos that MIX glyph families:
 *  - sextant mosaics (U+1FB00–1FB3B) and ▀ ▄ █ ▌ ▐ — drawn exactly
 *  - shade glyphs ░ ▒ ▓ — drawn as a stipple at 25 / 50 / 75 % (like a
 *    terminal's dotted shade cell), so texture is visible
 *  - braille (U+2800) — 2×4 dots
 *  - anything else (✦ ✧ ⋆ ˚ · ━ ─ letters …) — real DejaVu Sans Mono TTF
 * Understands tc / 256 / 16-colour SGR.
 *
 *   php mixed-glyph-preview.php <in.ansi> <out.png> [pageBg=#000000] [cellW=12] [cellH=24] [font.ttf]
 */

require_once __DIR__ . '/sextant-canvas.php';
require_once __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php mixed-glyph-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH] [font]\n");
$out = $argv[2] ?? exit("missing out.png\n");
$page = lkHex($argv[3] ?? '#000000');
$cw = (int) ($argv[4] ?? 12);
$ch = (int) ($argv[5] ?? 24);
$font = $argv[6] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

/** Parse SGR text into rows of [glyph, fg|null, bg|null]. */
function mgParse(string $ansi): array
{
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
                        if ($p[$i + 1] === 2) {
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
            foreach (mb_str_split($tok) as $g) {
                $row[] = [$g, $fg, $bg];
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

$rows = mgParse(lkLastFrame(file_get_contents($in)));
$W = max(array_map('count', $rows));
$img = imagecreatetruecolor($W * $cw, count($rows) * $ch);
$alloc = static fn (array $c): int => imagecolorallocate($img, $c[0], $c[1], $c[2]);
imagefill($img, 0, 0, $alloc($page));
$shade = ['░' => 0.25, '▒' => 0.5, '▓' => 0.75];
foreach ($rows as $y => $row) {
    foreach ($row as $x => [$g, $fg, $bg]) {
        $x0 = $x * $cw;
        $y0 = $y * $ch;
        if ($bg !== null) {
            imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($bg));
        }
        if ($g === ' ') {
            continue;
        }
        $col = $alloc($fg ?? [220, 220, 220]);
        $bits = scBits($g);
        if ($bits !== null) {
            for ($b = 0; $b < 6; $b++) {
                if ($bits & (1 << $b)) {
                    $sx = $x0 + ($b % 2) * intdiv($cw, 2);
                    $sy = $y0 + intdiv($b, 2) * intdiv($ch, 3);
                    $ex = $b % 2 ? $x0 + $cw - 1 : $sx + intdiv($cw, 2) - 1;
                    $ey = intdiv($b, 2) === 2 ? $y0 + $ch - 1 : $sy + intdiv($ch, 3) - 1;
                    imagefilledrectangle($img, $sx, $sy, $ex, $ey, $col);
                }
            }
        } elseif ($g === '▀' || $g === '▄') {
            $hy = $g === '▀' ? $y0 : $y0 + intdiv($ch, 2);
            imagefilledrectangle($img, $x0, $hy, $x0 + $cw - 1, $hy + intdiv($ch, 2) - 1, $col);
        } elseif (isset($shade[$g])) {
            // ordered stipple in 2×2 px dots
            $d = $shade[$g];
            $bayer = [[0, 2], [3, 1]];
            for ($py = 0; $py < $ch; $py += 2) {
                for ($px = 0; $px < $cw; $px += 2) {
                    if (($bayer[($py / 2) & 1][($px / 2) & 1] + 0.5) / 4 < $d) {
                        imagefilledrectangle($img, $x0 + $px, $y0 + $py, $x0 + $px + 1, $y0 + $py + 1, $col);
                    }
                }
            }
        } elseif (($o = mb_ord($g, 'UTF-8')) >= 0x2800 && $o <= 0x28FF) {
            $bb = $o - 0x2800;
            $map = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];
            $r = max(1, intdiv($cw, 5));
            foreach ($map as $dy => $pair) {
                foreach ($pair as $dx => $bit) {
                    if ($bb & $bit) {
                        imagefilledellipse($img, $x0 + (int) (($dx + 0.5) * $cw / 2), $y0 + (int) (($dy + 0.5) * $ch / 4), 2 * $r, 2 * $r, $col);
                    }
                }
            }
        } else {
            $size = $ch * 0.62;
            $bb = imagettfbbox($size, 0, $font, $g);
            $gw = $bb[2] - $bb[0];
            imagettftext($img, $size, 0, $x0 + (int) (($cw - $gw) / 2) - $bb[0], $y0 + (int) ($ch * 0.78), $col, $font, $g);
        }
    }
}
imagepng($img, $out);
fprintf(STDERR, "%s: %dx%d cells → %s\n", basename($in), $W, count($rows), $out);
