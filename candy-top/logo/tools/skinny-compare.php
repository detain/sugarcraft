<?php

declare(strict_types=1);

/**
 * skinny-compare — one PNG contact sheet stacking a logo's ORIGINAL over its
 * SKINNY variants (tc / 256 / 16), each labelled with filename + size, all
 * at the same cell scale, so you can judge in ONE image whether the skinny
 * version still reads as the same design and whether 256/16 hold up.
 * Animations are shown by their last (static) frame. Rendering uses
 * skinny-raster-kit.php's skRender (exact sextants, quadrants, half/eighth
 * blocks, ░▒▓, braille dots, wedges; DejaVu TTF for the rest).
 *
 * Usage:
 *   php skinny-compare.php --slug=<slug> --out=<sheet.png> [--cell=10x20] [--page=#000000]
 *       picks logo-<slug>-tc-*.ansi (original) + logo-<slug>-skinny-{tc,256,16}-*.ansi
 *   php skinny-compare.php --out=<sheet.png> a.ansi b.ansi …
 *       any list of files, top to bottom
 *   -h | --help
 *
 * Example:
 *   php skinny-compare.php --slug=phosphor-scope-trace --out=/tmp/me/sheet.png
 *   (then look at the PNG with your image viewer / Read tool)
 */

require_once __DIR__ . '/skinny-raster-kit.php';

$o = ['out' => null, 'slug' => null, 'cell' => '10x20', 'page' => '#000000'];
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
        echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
        exit(0);
    }
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $o[$m[1]] = $m[2];
    } else {
        array_push($files, ...(glob($arg) ?: [$arg]));
    }
}
$dir = dirname(__DIR__);
if ($o['slug'] !== null) {
    $s = $o['slug'];
    $files = [
        ...(glob("$dir/logo-$s-tc-*.ansi") ?: []),
        ...(glob("$dir/logo-$s-skinny-tc-*.ansi") ?: []),
        ...(glob("$dir/logo-$s-skinny-256-*.ansi") ?: []),
        ...(glob("$dir/logo-$s-skinny-16-*.ansi") ?: []),
    ];
}
if ($o['out'] === null || $files === []) {
    fwrite(STDERR, "usage: php skinny-compare.php --slug=<slug> --out=<png>  |  --out=<png> files…  (--help)\n");
    exit(1);
}
[$cw, $ch] = array_map('intval', explode('x', $o['cell']));
$page = lkHex($o['page']);
$labelH = 18;
$pad = 10;

$tiles = [];
foreach ($files as $f) {
    $cells = skLoad($f);
    $tiles[] = [basename($f), skRender($cells, $cw, $ch, null)];
}
$W = max(array_map(static fn (array $t): int => imagesx($t[1]), $tiles)) + 2 * $pad;
$H = array_sum(array_map(static fn (array $t): int => imagesy($t[1]) + $labelH + $pad, $tiles)) + $pad;
$sheet = imagecreatetruecolor($W, $H);
imagefill($sheet, 0, 0, imagecolorallocate($sheet, ...$page));
$labelC = imagecolorallocate($sheet, 170, 170, 190);
$y = $pad;
foreach ($tiles as [$name, $im]) {
    imagestring($sheet, 3, $pad, $y + 2, $name . sprintf('  (%dx%d)', intdiv(imagesx($im), $cw), intdiv(imagesy($im), $ch)), $labelC);
    $y += $labelH;
    imagealphablending($sheet, true);
    imagecopy($sheet, $im, $pad, $y, 0, 0, imagesx($im), imagesy($im));
    $y += imagesy($im) + $pad;
}
@mkdir(dirname($o['out']), 0777, true);
imagepng($sheet, $o['out']);
echo "wrote {$o['out']} (" . count($tiles) . " logos)\n";
