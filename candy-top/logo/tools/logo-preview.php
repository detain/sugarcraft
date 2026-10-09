<?php

declare(strict_types=1);

/**
 * Rasterise an .ansi logo (tc / 256 / 16 SGR; last frame of an animation)
 * to a PNG for eyeballing with an image viewer. Block, half-block, quadrant
 * and shade glyphs are drawn exactly; any other glyph is drawn with GD's
 * built-in font (approximate) so text and ornaments are still visible.
 *
 *   php logo-preview.php <in.ansi> <out.png> [bg=#1a1b26] [cellW=8] [cellH=16]
 *
 * Merged from licorice-allsorts-png.php + rock-candy-prism-preview.php.
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php logo-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH]\n");
$outPng = $argv[2] ?? 'preview.png';
$page = lkHex($argv[3] ?? '#1a1b26');
$cw = (int) ($argv[4] ?? 8);
$ch = (int) ($argv[5] ?? 16);

$x256 = static function (int $n): array {
    if ($n < 16) {
        return lkAnsi16()[$n < 8 ? 30 + $n : 82 + $n];
    }
    return lkXterm256()[$n];
};

$lines = explode("\n", rtrim(lkLastFrame(file_get_contents($in)), "\n"));
$rows = [];
$maxW = 0;
foreach ($lines as $line) {
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
                    $fg = [200, 200, 200];
                    $bg = null;
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
            if (mb_strwidth($g) === 2) {
                $cells[] = ['', $fg, $bg];
            }
        }
    }
    $maxW = max($maxW, count($cells));
    $rows[] = $cells;
}

$img = imagecreatetruecolor($maxW * $cw, count($rows) * $ch);
$col = static fn (array $c): int => imagecolorallocate($img, $c[0], $c[1], $c[2]);
imagefill($img, 0, 0, $col($page));
// quadrant bitmaps: [TL, TR, BL, BR]
$quad = [
    '▘' => [1, 0, 0, 0], '▝' => [0, 1, 0, 0], '▖' => [0, 0, 1, 0], '▗' => [0, 0, 0, 1],
    '▌' => [1, 0, 1, 0], '▐' => [0, 1, 0, 1], '▚' => [1, 0, 0, 1], '▞' => [0, 1, 1, 0],
    '▛' => [1, 1, 1, 0], '▜' => [1, 1, 0, 1], '▙' => [1, 0, 1, 1], '▟' => [0, 1, 1, 1],
];
$hw = intdiv($cw, 2);
$hh = intdiv($ch, 2);
foreach ($rows as $y => $cells) {
    foreach ($cells as $x => [$g, $fg, $bg]) {
        $x0 = $x * $cw;
        $y0 = $y * $ch;
        $x1 = $x0 + $cw - 1;
        $y1 = $y0 + $ch - 1;
        if ($bg !== null) {
            imagefilledrectangle($img, $x0, $y0, $x1, $y1, $col($bg));
        }
        $f = $col($fg);
        if (isset($quad[$g])) {
            foreach ($quad[$g] as $q => $on) {
                if ($on) {
                    $qx = $x0 + ($q % 2) * $hw;
                    $qy = $y0 + intdiv($q, 2) * $hh;
                    imagefilledrectangle($img, $qx, $qy, $qx + $hw - 1, $qy + $hh - 1, $f);
                }
            }
            continue;
        }
        $shade = ['░' => 0.25, '▒' => 0.5, '▓' => 0.75][$g] ?? null;
        if ($shade !== null) {
            $base = $bg ?? $page;
            imagefilledrectangle($img, $x0, $y0, $x1, $y1, $col(lkMix($base, $fg, $shade)));
            continue;
        }
        match ($g) {
            ' ', '' => null,
            '█' => imagefilledrectangle($img, $x0, $y0, $x1, $y1, $f),
            '▀' => imagefilledrectangle($img, $x0, $y0, $x1, $y0 + $hh - 1, $f),
            '▄' => imagefilledrectangle($img, $x0, $y0 + $hh, $x1, $y1, $f),
            '▔' => imagefilledrectangle($img, $x0, $y0, $x1, $y0 + 1, $f),
            '▁' => imagefilledrectangle($img, $x0, $y1 - 1, $x1, $y1, $f),
            '◢' => imagefilledpolygon($img, [$x1, $y0, $x1, $y1, $x0, $y1], $f),
            '◣' => imagefilledpolygon($img, [$x0, $y0, $x0, $y1, $x1, $y1], $f),
            '◤' => imagefilledpolygon($img, [$x0, $y0, $x1, $y0, $x0, $y1], $f),
            '◥' => imagefilledpolygon($img, [$x0, $y0, $x1, $y0, $x1, $y1], $f),
            default => strlen($g) === 1
                ? imagestring($img, 3, $x0, $y0 + 1, $g, $f)
                : imagefilledrectangle($img, $x0 + $hw - 1, $y0 + $hh - 1, $x0 + $hw, $y0 + $hh, $f),
        };
    }
}
imagepng($img, $outPng);
echo "wrote $outPng\n";
