<?php

declare(strict_types=1);

/**
 * skinny — one front-end for all skinny-logo command-line tools.
 *
 * Usage:
 *   php skinny.php <command> [args…]       (args are passed through unchanged)
 *   php skinny.php <command> --help        that command's own full help
 *   php skinny.php help                    this list
 *
 * Plan
 *   fit        skinny-type-fit.php     fit TTF words into cell boxes, report thin strokes
 *   stroke     skinny-stroke-font.php  centre-line font layout preview (stack CANDY/TOP)
 *   btop       skinny-btop-parts.php   demo btop frame/graph/meter pieces (--demo=40x9)
 * Draft from an existing logo
 *   carve      skinny-seamcarve.php    content-aware condense any .ansi to ≤40 cols
 *   compose    skinny-b-compose.php    cut/paste/rescale rectangles from existing logos
 * Colour depth
 *   palette    skinny-palette.php      tc file → 256 + 16 files, hue-faithful (--probe=…)
 *   depth      skinny-depth.php        tc file → one depth to stdout (--depth=256|16)
 * Verify / look
 *   check      skinny-b-check.php      verify files (widths, \e[0m, ≤40, 5-15 rows, purity, name WxH, --jsonl)
 *   sheet      skinny-sheet.php        contact-sheet PNG of any files
 *   compare    skinny-compare.php      PNG: original over its skinny tc/256/16 (--slug=…)
 *
 * Libraries: require_once 'skinny-kit.php' to get every sk, skb, ssf, stf, sp, sd and sb function.
 */

const SKINNY_CMDS = [
    'fit' => 'skinny-type-fit', 'stroke' => 'skinny-stroke-font', 'btop' => 'skinny-btop-parts',
    'carve' => 'skinny-seamcarve', 'compose' => 'skinny-b-compose',
    'palette' => 'skinny-palette', 'depth' => 'skinny-depth',
    'check' => 'skinny-b-check', 'sheet' => 'skinny-sheet', 'compare' => 'skinny-compare',
];

$cmd = $argv[1] ?? 'help';
if (!isset(SKINNY_CMDS[$cmd])) {
    $doc = (string) file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    echo preg_replace('/^ ?\* ?/m', '', trim($m[1])), "\n";
    exit($cmd === 'help' || $cmd === '--help' || $cmd === '-h' ? 0 : 1);
}
$args = array_map('escapeshellarg', array_slice($argv, 2));
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . SKINNY_CMDS[$cmd] . '.php') . ' ' . implode(' ', $args), $rc);
exit($rc);
