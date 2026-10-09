<?php

declare(strict_types=1);

/**
 * Rasterise an .ansi logo to PNG for eyeballing (GD). Understands tc / 256 /
 * 16-colour SGR, block glyphs (█ ▀ ▄ ▔) exactly, other glyphs as a centred dot.
 *
 *   php rock-candy-prism-preview.php <in.ansi> <out.png> [cellW=8] [cellH=16]
 */

$in = $argv[1] ?? exit("usage: preview <in.ansi> <out.png>\n");
$outPng = $argv[2] ?? 'preview.png';
$cw = (int) ($argv[3] ?? 8);
$ch = (int) ($argv[4] ?? 16);
$data = file_get_contents($in);
// keep only the final frame of an animation
if (preg_match_all('/\e\[\d+A\r/', $data, $m, PREG_OFFSET_CAPTURE)) {
    $last = end($m[0]);
    $data = substr($data, $last[1] + strlen($last[0]));
}
$data = preg_replace('/\e\[\?25[hl]/', '', $data);
$lines = explode("\n", rtrim($data, "\n"));

$ansi16 = [
    0 => [0, 0, 0], 1 => [205, 49, 49], 2 => [13, 188, 121], 3 => [229, 229, 16], 4 => [36, 114, 200],
    5 => [188, 63, 188], 6 => [17, 168, 205], 7 => [229, 229, 229], 8 => [102, 102, 102], 9 => [241, 76, 76],
    10 => [35, 209, 139], 11 => [245, 245, 67], 12 => [59, 142, 234], 13 => [214, 112, 214], 14 => [41, 184, 219],
    15 => [255, 255, 255],
];
$x256 = static function (int $n) use ($ansi16): array {
    if ($n < 16) {
        return $ansi16[$n];
    }
    if ($n >= 232) {
        $v = 8 + ($n - 232) * 10;
        return [$v, $v, $v];
    }
    $n -= 16;
    $s = [0, 95, 135, 175, 215, 255];
    return [$s[intdiv($n, 36)], $s[intdiv($n, 6) % 6], $s[$n % 6]];
};

$rows = [];
$maxW = 0;
foreach ($lines as $line) {
    $fg = [200, 200, 200];
    $bg = null;
    $cells = [];
    foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
        if ($tok[0] === "\e") {
            $p = array_map('intval', explode(';', trim($tok, "\e[m")));
            for ($i = 0; $i < count($p); $i++) {
                $c = $p[$i];
                if ($c === 0) {
                    $fg = [200, 200, 200];
                    $bg = null;
                } elseif ($c === 38 || $c === 48) {
                    $rgb = $p[$i + 1] === 2 ? [$p[$i + 2], $p[$i + 3], $p[$i + 4]] : $x256($p[$i + 2]);
                    $i += $p[$i + 1] === 2 ? 4 : 2;
                    $c === 38 ? $fg = $rgb : $bg = $rgb;
                } elseif ($c === 49) {
                    $bg = null;
                } elseif ($c >= 30 && $c <= 37) {
                    $fg = $ansi16[$c - 30];
                } elseif ($c >= 90 && $c <= 97) {
                    $fg = $ansi16[$c - 82];
                } elseif ($c >= 40 && $c <= 47) {
                    $bg = $ansi16[$c - 40];
                } elseif ($c >= 100 && $c <= 107) {
                    $bg = $ansi16[$c - 92];
                }
            }
            continue;
        }
        foreach (mb_str_split($tok) as $g) {
            $cells[] = [$g, $fg, $bg];
        }
    }
    $maxW = max($maxW, count($cells));
    $rows[] = $cells;
}

$img = imagecreatetruecolor($maxW * $cw, count($rows) * $ch);
$col = static fn (array $c): int => imagecolorallocate($img, $c[0], $c[1], $c[2]);
imagefill($img, 0, 0, $col([26, 27, 38]));
foreach ($rows as $y => $cells) {
    foreach ($cells as $x => [$g, $fg, $bg]) {
        $x0 = $x * $cw;
        $y0 = $y * $ch;
        if ($bg !== null) {
            imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $col($bg));
        }
        match ($g) {
            ' ' => null,
            '█' => imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $col($fg)),
            '▀' => imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + intdiv($ch, 2) - 1, $col($fg)),
            '▄' => imagefilledrectangle($img, $x0, $y0 + intdiv($ch, 2), $x0 + $cw - 1, $y0 + $ch - 1, $col($fg)),
            '▔' => imagefilledrectangle($img, $x0, $y0, $x0 + $cw - 1, $y0 + 1, $col($fg)),
            '✦' => imagefilledellipse($img, $x0 + intdiv($cw, 2), $y0 + intdiv($ch, 2), $cw - 2, $cw - 2, $col($fg)),
            '✧' => imageellipse($img, $x0 + intdiv($cw, 2), $y0 + intdiv($ch, 2), $cw - 2, $cw - 2, $col($fg)),
            default => imagefilledrectangle($img, $x0 + intdiv($cw, 2) - 1, $y0 + intdiv($ch, 2) - 1, $x0 + intdiv($cw, 2), $y0 + intdiv($ch, 2), $col($fg)),
        };
    }
}
imagepng($img, $outPng);
echo "wrote $outPng\n";
