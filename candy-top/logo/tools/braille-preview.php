<?php

declare(strict_types=1);

/**
 * Braille- and box-drawing-accurate PNG preview of an .ansi logo (tc / 256 /
 * 16; last frame of an animation). logo-preview.php only approximates
 * braille (one dot per cell); this one draws all 8 dot positions of every
 * U+2800 glyph as round dots, and light box-drawing (─│╭╮╰╯┬┴├┤┼) as lines,
 * so braille / vector-stroke logos can be judged by eye. Other non-ASCII
 * glyphs fall back to a centred dot; ASCII uses GD's built-in font.
 *
 *   php braille-preview.php <in.ansi> <out.png> [bg=#1a1b26] [cellW=10] [cellH=20]
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php braille-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH]\n");
$outPng = $argv[2] ?? 'preview.png';
$page = lkHex($argv[3] ?? '#1a1b26');
$cw = (int) ($argv[4] ?? 10);
$ch = (int) ($argv[5] ?? 20);

/** Parse SGR text into rows of [glyph, fg, bg]. */
function bpParse(string $ansi): array
{
    $x256 = static fn (int $n): array => $n < 16 ? lkAnsi16()[$n < 8 ? 30 + $n : 82 + $n] : lkXterm256()[$n];
    $rows = [];
    foreach (explode("\n", rtrim(lkLastFrame($ansi), "\n")) as $line) {
        $fg = [200, 200, 200];
        $bg = null;
        $cells = [];
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if ($tok[0] === "\e") {
                $seq = substr($tok, 2, -1);
                $p = $seq === '' ? [0] : array_map('intval', explode(';', $seq));
                for ($i = 0; $i < count($p); $i++) {
                    $c = $p[$i];
                    if ($c === 0) {
                        [$fg, $bg] = [[200, 200, 200], null];
                    } elseif ($c === 38 || $c === 48) {
                        $rgb = $p[$i + 1] === 2 ? [$p[$i + 2], $p[$i + 3], $p[$i + 4]] : $x256($p[$i + 2]);
                        $i += $p[$i + 1] === 2 ? 4 : 2;
                        $c === 38 ? $fg = $rgb : $bg = $rgb;
                    } elseif ($c === 39) {
                        $fg = [200, 200, 200];
                    } elseif ($c === 49) {
                        $bg = null;
                    } elseif (($c >= 30 && $c <= 37) || ($c >= 90 && $c <= 97)) {
                        $fg = lkAnsi16()[$c];
                    } elseif (($c >= 40 && $c <= 47) || ($c >= 100 && $c <= 107)) {
                        $bg = lkAnsi16()[$c - 10];
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $cells[] = [$g, $fg, $bg];
            }
        }
        $rows[] = $cells;
    }
    return $rows;
}

// box glyph → [left, right, up, down] arms
const BP_BOX = [
    '─' => [1, 1, 0, 0], '│' => [0, 0, 1, 1], '╭' => [0, 1, 0, 1], '╮' => [1, 0, 0, 1],
    '╰' => [0, 1, 1, 0], '╯' => [1, 0, 1, 0], '┬' => [1, 1, 0, 1], '┴' => [1, 1, 1, 0],
    '├' => [0, 1, 1, 1], '┤' => [1, 0, 1, 1], '┼' => [1, 1, 1, 1],
];

$rows = bpParse(file_get_contents($in));
$maxW = max(array_map('count', $rows));
$img = imagecreatetruecolor($maxW * $cw, count($rows) * $ch);
$col = static fn (array $c): int => imagecolorallocate($img, $c[0], $c[1], $c[2]);
imagefill($img, 0, 0, $col($page));
$dotR = max(2, (int) round($cw * 0.32));
foreach ($rows as $y => $cells) {
    foreach ($cells as $x => [$g, $fg, $bg]) {
        $x0 = $x * $cw;
        $y0 = $y * $ch;
        if ($bg !== null) {
            imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $col($bg));
        }
        $f = $col($fg);
        $cp = mb_ord($g);
        if ($cp >= 0x2800 && $cp <= 0x28FF) {
            $bits = $cp - 0x2800;
            $map = [[0, 0, 0x01], [0, 1, 0x02], [0, 2, 0x04], [1, 0, 0x08], [1, 1, 0x10], [1, 2, 0x20], [0, 3, 0x40], [1, 3, 0x80]];
            foreach ($map as [$dx, $dy, $bit]) {
                if ($bits & $bit) {
                    imagefilledellipse($img, (int) ($x0 + $cw * (0.27 + 0.46 * $dx)), (int) ($y0 + $ch * (0.125 + 0.25 * $dy)), $dotR, $dotR, $f);
                }
            }
        } elseif (isset(BP_BOX[$g])) {
            [$l, $r, $u, $d] = BP_BOX[$g];
            $mx = $x0 + intdiv($cw, 2);
            $my = $y0 + intdiv($ch, 2);
            $l && imageline($img, $x0, $my, $mx, $my, $f);
            $r && imageline($img, $mx, $my, $x0 + $cw - 1, $my, $f);
            $u && imageline($img, $mx, $y0, $mx, $my, $f);
            $d && imageline($img, $mx, $my, $mx, $y0 + $ch - 1, $f);
        } elseif ($g === '●') {
            imagefilledellipse($img, $x0 + intdiv($cw, 2), $y0 + intdiv($ch, 2), $cw - 2, $cw - 2, $f);
        } elseif ($g !== ' ' && $g !== '') {
            strlen($g) === 1
                ? imagestring($img, 3, $x0 + 1, $y0 + intdiv($ch - 13, 2), $g, $f)
                : imagefilledellipse($img, $x0 + intdiv($cw, 2), $y0 + intdiv($ch, 2), 3, 3, $f);
        }
    }
}
imagepng($img, $outPng);
echo "wrote $outPng\n";
