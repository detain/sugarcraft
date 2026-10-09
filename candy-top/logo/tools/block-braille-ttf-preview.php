<?php

declare(strict_types=1);

/**
 * block-braille-ttf-preview — PNG preview that draws the glyphs the other
 * previewers fake: lower-eighth blocks ▁▂▃▄▅▆▇█ and upper half ▀ as exact
 * rectangles, braille as all 8 dots, and everything else with the real
 * DejaVu Sans Mono TTF (box drawing, ○°∘ symbols, digits). Same SGR parser
 * as ansi-ttf-preview.php; renders the last frame of an animation.
 *
 *   php block-braille-ttf-preview.php <in.ansi> <out.png> [pageBg=#000000] [cellW=12] [cellH=24] [font.ttf]
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php block-braille-ttf-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH] [font.ttf]\n");
$outPng = $argv[2] ?? 'preview.png';
$page = lkHex($argv[3] ?? '#000000');
$cw = (int) ($argv[4] ?? 12);
$ch = (int) ($argv[5] ?? 24);
$font = $argv[6] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

$ansi = lkLastFrame(file_get_contents($in));
$lines = explode("\n", rtrim($ansi, "\n"));

$pal16 = lkAnsi16();
$x256 = lkXterm256();
foreach ([30, 31, 32, 33, 34, 35, 36, 37] as $i => $c) {
    $x256[$i] = $pal16[$c];
    $x256[$i + 8] = $pal16[$c + 60];
}

$grid = [];
foreach ($lines as $y => $line) {
    $fg = [229, 229, 229];
    $bg = null;
    $x = 0;
    foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
            $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
            for ($i = 0; $i < count($p); $i++) {
                $v = $p[$i];
                if ($v === 0) {
                    $fg = [229, 229, 229];
                    $bg = null;
                } elseif ($v === 39) {
                    $fg = [229, 229, 229];
                } elseif ($v === 49) {
                    $bg = null;
                } elseif (($v >= 30 && $v <= 37) || ($v >= 90 && $v <= 97)) {
                    $fg = $pal16[$v];
                } elseif (($v >= 40 && $v <= 47) || ($v >= 100 && $v <= 107)) {
                    $bg = $pal16[$v - 10];
                } elseif ($v === 38 || $v === 48) {
                    if (($p[$i + 1] ?? 0) === 5) {
                        $c = $x256[$p[$i + 2]];
                        $i += 2;
                    } else {
                        $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                        $i += 4;
                    }
                    $v === 38 ? $fg = $c : $bg = $c;
                }
            }
            continue;
        }
        foreach (mb_str_split($tok) as $g) {
            $grid[$y][$x] = [$g, $fg, $bg];
            $x += max(1, mb_strwidth($g));
        }
    }
}

$w = max(array_map(static fn (array $r): int => max(array_keys($r)) + 1, $grid));
$h = count($lines);
$im = imagecreatetruecolor($w * $cw, $h * $ch);
$col = static fn (array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($page));
$size = $ch * 0.62;
$eighths = ['▁' => 1, '▂' => 2, '▃' => 3, '▄' => 4, '▅' => 5, '▆' => 6, '▇' => 7, '█' => 8];
foreach ($grid as $y => $row) {
    foreach ($row as $x => [$g, $fg, $bg]) {
        $x0 = $x * $cw;
        $y0 = $y * $ch;
        if ($bg !== null) {
            imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $col($bg));
        }
        if ($g === ' ') {
            continue;
        }
        $cp = mb_ord($g);
        if (isset($eighths[$g])) {
            $top = $y0 + (int) round($ch * (8 - $eighths[$g]) / 8);
            imagefilledrectangle($im, $x0, $top, $x0 + $cw - 1, $y0 + $ch - 1, $col($fg));
        } elseif ($g === '▀') {
            imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + intdiv($ch, 2) - 1, $col($fg));
        } elseif ($cp >= 0x2800 && $cp <= 0x28FF) {
            $bits = $cp - 0x2800;
            $dots = [[0, 0, 0x01], [0, 1, 0x02], [0, 2, 0x04], [1, 0, 0x08], [1, 1, 0x10], [1, 2, 0x20], [0, 3, 0x40], [1, 3, 0x80]];
            $r = max(1, (int) round(min($cw / 2, $ch / 4) * 0.36));
            foreach ($dots as [$dx, $dy, $bit]) {
                if ($bits & $bit) {
                    imagefilledellipse($im, (int) ($x0 + $cw * (0.25 + 0.5 * $dx)), (int) ($y0 + $ch * (0.125 + 0.25 * $dy)), 2 * $r, 2 * $r, $col($fg));
                }
            }
        } else {
            imagettftext($im, $size, 0, $x0, (int) ($y0 + $ch * 0.78), $col($fg), $font, $g);
        }
    }
}
imagepng($im, $outPng);
echo "wrote $outPng ({$w}x{$h} cells)\n";
