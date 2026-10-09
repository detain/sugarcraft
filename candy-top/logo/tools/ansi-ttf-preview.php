<?php

declare(strict_types=1);

/**
 * ansi-ttf-preview — render an .ansi logo to PNG with a REAL monospace TTF
 * (DejaVu Sans Mono by default), so box-drawing, braille, diagonals and any
 * other glyph look like they do in a terminal. Complements logo-preview.php,
 * which approximates non-block glyphs as dots.
 *
 * Understands SGR 0, 39/49, 30-37/90-97, 40-47/100-107, 38;5/48;5, 38;2/48;2.
 * For animations it renders the LAST frame (text after the final \e[<n>A\r).
 *
 *   php ansi-ttf-preview.php <in.ansi> <out.png> [pageBg=#000000] [cellW=12] [cellH=24] [font.ttf]
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php ansi-ttf-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH] [font.ttf]\n");
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
foreach ($grid as $y => $row) {
    foreach ($row as $x => [$g, $fg, $bg]) {
        if ($bg !== null) {
            imagefilledrectangle($im, $x * $cw, $y * $ch, ($x + 1) * $cw - 1, ($y + 1) * $ch - 1, $col($bg));
        }
        if ($g !== ' ') {
            imagettftext($im, $size, 0, $x * $cw, (int) ($y * $ch + $ch * 0.78), $col($fg), $font, $g);
        }
    }
}
imagepng($im, $outPng);
echo "wrote $outPng ({$w}x{$h} cells)\n";
