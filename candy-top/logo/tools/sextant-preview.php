<?php

declare(strict_types=1);

/**
 * sextant-preview — render an .ansi logo (last frame of an animation) to PNG
 * with exact sextant mosaics (U+1FB00–1FB3B), braille dots (U+2800), ▌ ▐ █ ▀ ▄, and small squares
 * for dot-like glyphs (· ⋅ ˙ ✧ ✦ …). Understands tc (38;2 / 48;2), 256
 * (38;5 / 48;5) and 16-colour (30-37/90-97, 40-47/100-107) SGR.
 * Unknown glyphs are drawn as a faint centred block so nothing is lost.
 *
 *   php sextant-preview.php <in.ansi> <out.png> [bg=#1a1b26] [cellW=10] [cellH=20]
 */

require __DIR__ . '/sextant-canvas.php';
require_once __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php sextant-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH]\n");
$out = $argv[2] ?? exit("missing out.png\n");
$bgHex = $argv[3] ?? '#1a1b26';
$cw = (int) ($argv[4] ?? 10);
$ch = (int) ($argv[5] ?? 20);

$frame = lkLastFrame(file_get_contents($in));
$lines = explode("\n", rtrim($frame, "\n"));
$x256 = lkXterm256();
$x16 = lkAnsi16();

/** @return list<list<array{0:string,1:?array,2:?array}>> */
function spParse(array $lines, array $x256, array $x16): array
{
    $rows = [];
    foreach ($lines as $line) {
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

$rows = spParse($lines, $x256, $x16);
$W = max(array_map('count', $rows));
$img = imagecreatetruecolor($W * $cw, count($rows) * $ch);
$alloc = static fn (array $c) => imagecolorallocate($img, $c[0], $c[1], $c[2]);
$base = lkHex($bgHex);
imagefill($img, 0, 0, $alloc($base));
$dots = ['·' => 0.18, '⋅' => 0.14, '˙' => 0.14, '.' => 0.14, '✧' => 0.3, '✦' => 0.34, '*' => 0.3, '+' => 0.3, '⁺' => 0.24];
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
        $bits = scBits($g) ?? match ($g) {
            '▀' => -1, '▄' => -2, default => null,
        };
        if ($bits === -1 || $bits === -2) {
            $hy = $bits === -1 ? $y0 : $y0 + intdiv($ch, 2);
            imagefilledrectangle($img, $x0, $hy, $x0 + $cw - 1, $hy + intdiv($ch, 2) - 1, $col);
        } elseif (($o = mb_ord($g, 'UTF-8')) >= 0x2800 && $o <= 0x28FF) {
            // braille: 2×4 round-ish dots
            $bb = $o - 0x2800;
            $map = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];
            $r = max(1, intdiv($cw, 5));
            foreach ($map as $dy => $pair) {
                foreach ($pair as $dx => $bit) {
                    if ($bb & $bit) {
                        $cx = $x0 + (int) (($dx + 0.5) * $cw / 2);
                        $cy = $y0 + (int) (($dy + 0.5) * $ch / 4);
                        imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $col);
                    }
                }
            }
        } elseif ($bits !== null) {
            for ($b = 0; $b < 6; $b++) {
                if ($bits & (1 << $b)) {
                    $sx = $x0 + ($b % 2) * intdiv($cw, 2);
                    $sy = $y0 + intdiv($b, 2) * intdiv($ch, 3);
                    $ey = intdiv($b, 2) === 2 ? $y0 + $ch - 1 : $sy + intdiv($ch, 3) - 1;
                    imagefilledrectangle($img, $sx, $sy, $sx + intdiv($cw, 2) - 1 + ($b % 2), $ey, $col);
                }
            }
        } else {
            $r = (int) max(1, round(($dots[$g] ?? 0.4) * $cw));
            $cx = $x0 + intdiv($cw, 2);
            $cy = $y0 + intdiv($ch, 2);
            imagefilledrectangle($img, $cx - intdiv($r, 2), $cy - intdiv($r, 2), $cx - intdiv($r, 2) + $r - 1, $cy - intdiv($r, 2) + $r - 1, $col);
        }
    }
}
imagepng($img, $out);
fprintf(STDERR, "%s: %dx%d cells → %s\n", basename($in), $W, count($rows), $out);
