<?php

declare(strict_types=1);

/**
 * skinny-sheet — contact sheet: render several .ansi logos into ONE PNG, each
 * labelled with its filename and WxH, so an original and its skinny variant
 * (or the tc / 256 / 16 depths, or several drafts) can be compared in a
 * single Read. Animated files show their last frame. Rendering is the exact
 * skinny-raster-kit renderer (sextants, quadrants, half/eighth blocks, shades,
 * braille dots, wedges; DejaVu TTF for box-drawing/text).
 *
 * Usage:
 *   php skinny-sheet.php --out=SHEET.png [options] <file.ansi|'glob'>...
 *     --cell=WxH       pixel size of one terminal cell (default 8x16; 12x24 for detail)
 *     --cols=N         lay files out in N columns (default 1 = stacked)
 *     --page=#000000   page colour (default black, like the logos' target)
 *     --pad=N          pixels between tiles (default 12)
 *   php skinny-sheet.php --help
 *
 * Examples:
 *   php skinny-sheet.php --out=/tmp/x/sheet.png ../logo-art-deco-gilt-tc-76x9.ansi \
 *       '../logo-art-deco-gilt-skinny-*-40x*.ansi'
 *   php skinny-sheet.php --cols=3 --out=/tmp/x/depths.png '../logo-foo-skinny-*.ansi'
 */

require_once __DIR__ . '/skinny-raster-kit.php';

$args = array_slice($argv, 1);
if (!$args || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
    exit($args ? 0 : 2);
}
$out = null;
$cw = 8;
$ch = 16;
$cols = 1;
$page = '#000000';
$pad = 12;
$files = [];
foreach ($args as $a) {
    if (preg_match('/^--out=(.+)$/', $a, $m)) {
        $out = $m[1];
    } elseif (preg_match('/^--cell=(\d+)x(\d+)$/', $a, $m)) {
        [$cw, $ch] = [(int) $m[1], (int) $m[2]];
    } elseif (preg_match('/^--cols=(\d+)$/', $a, $m)) {
        $cols = max(1, (int) $m[1]);
    } elseif (preg_match('/^--page=(#[0-9a-fA-F]{6})$/', $a, $m)) {
        $page = $m[1];
    } elseif (preg_match('/^--pad=(\d+)$/', $a, $m)) {
        $pad = (int) $m[1];
    } else {
        array_push($files, ...(glob($a) ?: [$a]));
    }
}
if ($out === null || !$files) {
    fwrite(STDERR, "need --out=FILE.png and at least one .ansi file (--help)\n");
    exit(2);
}
$labelH = 16;
$tiles = [];
foreach ($files as $f) {
    $cells = skLoad($f);
    $im = skRender($cells, $cw, $ch, lkHex($page));
    $tiles[] = [$im, sprintf('%s  %dx%d', basename($f), skWidth($cells), count($cells))];
}
$rows = (int) ceil(count($tiles) / $cols);
$colW = array_fill(0, $cols, 0);
$rowH = array_fill(0, $rows, 0);
foreach ($tiles as $i => [$im]) {
    $colW[$i % $cols] = max($colW[$i % $cols], imagesx($im), 300);
    $rowH[intdiv($i, $cols)] = max($rowH[intdiv($i, $cols)], imagesy($im) + $labelH);
}
$W = array_sum($colW) + $pad * ($cols + 1);
$H = array_sum($rowH) + $pad * ($rows + 1);
$sheet = imagecreatetruecolor($W, $H);
$bg = lkHex($page);
imagefill($sheet, 0, 0, imagecolorallocate($sheet, $bg[0], $bg[1], $bg[2]));
$sep = imagecolorallocate($sheet, 60, 60, 70);
$txt = imagecolorallocate($sheet, 170, 170, 185);
foreach ($tiles as $i => [$im, $label]) {
    $c = $i % $cols;
    $r = intdiv($i, $cols);
    $x = $pad + array_sum(array_slice($colW, 0, $c)) + $pad * $c;
    $y = $pad + array_sum(array_slice($rowH, 0, $r)) + $pad * $r;
    imagettftext($sheet, 9, 0, $x, $y + 11, $txt, SK_FONT, $label);
    imagecopy($sheet, $im, $x, $y + $labelH, 0, 0, imagesx($im), imagesy($im));
    imagerectangle($sheet, $x - 1, $y + $labelH - 1, $x + imagesx($im), $y + $labelH + imagesy($im), $sep);
}
@mkdir(dirname($out), 0777, true);
imagepng($sheet, $out);
fwrite(STDERR, sprintf("sheet: %d file(s) → %s (%dx%d px)\n", count($tiles), $out, $W, $H));
