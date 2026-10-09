<?php

declare(strict_types=1);

/**
 * Preview a half-block / block .ansi logo as a PNG (tc, 256 or 16 SGR).
 * Usage: php licorice-allsorts-png.php in.ansi out.png [bg-hex=#1a1b26] [scale=6]
 * Only full/half block glyphs are painted as pixels; other glyphs are drawn as a filled
 * cell in their fg colour (approximate).
 */

require __DIR__ . '/licorice-allsorts-lib.php';

[$_, $in, $out] = $argv + [null, null, null];
$bg = hex_rgb($argv[3] ?? '#1a1b26');
$scale = (int) ($argv[4] ?? 6);

$pal = xterm256();
foreach (ansi16() as $code => $c) { $pal[$code - 30 < 8 ? $code - 30 : $code - 82] = $c; }

// Last frame only (animated files redraw with cursor-up).
$data = file_get_contents($in);
$parts = preg_split('/\e\[\d+A\r/', $data);
$data = end($parts);
$lines = explode("\n", rtrim(preg_replace('/\e\[\?25[hl]/', '', $data), "\n"));

$rows = [];
$w = 0;
foreach ($lines as $line) {
    $fg = null; $bgc = null; $cells = [];
    foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $tok) {
        if ($tok === '') { continue; }
        if ($tok[0] === "\e") {
            $p = array_map('intval', explode(';', substr($tok, 2, -1)));
            for ($i = 0; $i < count($p); $i++) {
                $v = $p[$i];
                if ($v === 0) { $fg = $bgc = null; }
                elseif ($v === 38 || $v === 48) {
                    if ($p[$i + 1] === 2) { $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]]; $i += 4; }
                    else { $c = $pal[$p[$i + 2]]; $i += 2; }
                    if ($v === 38) { $fg = $c; } else { $bgc = $c; }
                }
                elseif ($v === 39) { $fg = null; } elseif ($v === 49) { $bgc = null; }
                elseif (($v >= 30 && $v <= 37) || ($v >= 90 && $v <= 97)) { $fg = ansi16()[$v]; }
                elseif (($v >= 40 && $v <= 47) || ($v >= 100 && $v <= 107)) { $bgc = ansi16()[$v - 10]; }
            }
            continue;
        }
        foreach (mb_str_split($tok) as $ch) {
            $cells[] = match ($ch) {
                '▀' => [$fg, $bgc],
                '▄' => [$bgc, $fg],
                '█' => [$fg, $fg],
                ' ' => [$bgc, $bgc],
                default => [$fg, $fg],
            };
        }
    }
    $rows[] = $cells;
    $w = max($w, count($cells));
}
$img = imagecreatetruecolor($w * $scale, count($rows) * 2 * $scale);
imagefill($img, 0, 0, imagecolorallocate($img, ...$bg));
foreach ($rows as $y => $cells) {
    foreach ($cells as $x => [$t, $b]) {
        foreach ([$t, $b] as $half => $c) {
            if ($c === null) { continue; }
            $col = imagecolorallocate($img, ...$c);
            imagefilledrectangle($img, $x * $scale, ($y * 2 + $half) * $scale, ($x + 1) * $scale - 1, ($y * 2 + $half + 1) * $scale - 1, $col);
        }
    }
}
imagepng($img, $out);
