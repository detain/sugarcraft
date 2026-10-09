<?php

declare(strict_types=1);

/**
 * skinny-btop-parts — btop / candy-top UI pieces for logos, drawn straight into
 * a logo-kit cell grid ([glyph, fg, bg] cells). Generic and size-agnostic:
 * works for 40-col skinny logos and full-size ones. Colours default to
 * candy-top's own default theme (src/Theme/ThemeConfig.php = btop "Default").
 *
 * Every drawing call also records a 16-colour code for each colour it puts
 * down in $pins (rgb "r,g,b" => 30-37/90-97), so lkEncode($cells, '16', $pins)
 * renders the UI in the intended ANSI colours even when the colours are dim.
 *
 * Library API (require_once it):
 *   sbTheme()                               btop default theme: role => '#hex' + gradients
 *   sbGrad(name)                            gradient stops for cpu|used|free|cached|available|
 *                                           download|upload|temp|process
 *   sbPut(&cells, &pins, x, y, glyph, rgb, code16, bg = [0,0,0])
 *   sbBox(&cells, &pins, x0, y0, x1, y1, rgb, code16, round = true)   rounded box (╭╮╰╯│─)
 *   sbTitle(&cells, &pins, x, y, label, num = null, lineRgb, …)        ┐¹label┌ tab on a box edge
 *   sbFrame(&cells, &pins, w, h, ['top' => runs, 'right' => runs, 'botLeft' => runs, 'botRight' => runs], opts)
 *       outer btop panel: rounded box + title tabs; runs are [[text, role], …]
 *       with roles line|hi|title|fg|down|up or '#hex'. Tabs that would collide
 *       are dropped automatically from the end of the 'top'/'botLeft' lists,
 *       so the same spec fits 40 or 80 columns.
 *   sbTabs(['cpu','mem','net','proc'])      → runs "┐¹cpu┌─┐²mem┌ …" for sbFrame 'top'
 *   sbArea(levels[2], offset, fromTop, totalDots)  one braille area-graph cell → [glyph, t] | null
 *   sbGraph(&cells, &pins, x0, y0, x1, y1, series, grad, opts)
 *       braille area graph filling the box (from the bottom, or hanging from the
 *       top with 'fromTop'), coloured by height along grad, scaled by
 *       'bright' (0-1), faded toward black at the canvas sides over 'fade'
 *       cells, and skipping cells for which 'skip'(cx, cy) returns true (e.g.
 *       cells reserved for lettering). 'shift' scrolls the series (animation).
 *   sbMeter(&cells, &pins, x, y, w, fill 0..1, grad, opts)   btop ■ meter row
 *   sbSeries(n, seed, base, burst, smooth)  seeded 0..1 traffic (bnTraffic)
 *
 * CLI demo (renders a sample panel so you can see the parts):
 *   php skinny-btop-parts.php --demo[=40x9] [--depth=tc|256|16]
 *   php skinny-btop-parts.php --help
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/braille-netgraph.php';   // bnTraffic

const SB_BITS = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];
const SB_SUPERSCRIPT = ['¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹'];

/** candy-top / btop default theme. */
function sbTheme(): array
{
    return [
        'main_fg' => '#cccccc', 'title' => '#eeeeee', 'hi_fg' => '#b54040', 'inactive_fg' => '#404040',
        'graph_text' => '#606060', 'meter_bg' => '#404040', 'div_line' => '#303030',
        'cpu_box' => '#556d59', 'mem_box' => '#6c6c4b', 'net_box' => '#5c588d', 'proc_box' => '#805252',
        'cpu' => ['#77ca9b', '#cbc06c', '#dc4c4c'],
        'used' => ['#592b26', '#d9626d', '#ff4769'],
        'free' => ['#384f21', '#b5e685', '#dcff85'],
        'cached' => ['#163350', '#74e6fc', '#26c5ff'],
        'available' => ['#4e3f0e', '#ffd77a', '#ffb814'],
        'download' => ['#291f75', '#4f43a3', '#b0a9de'],
        'upload' => ['#620665', '#7d4180', '#dcafde'],
        'temp' => ['#4897d4', '#5474e8', '#ff40b6'],
        'process' => ['#80d0a3', '#dcd179', '#d45454'],
    ];
}

function sbGrad(string $name): array
{
    return sbTheme()[$name] ?? throw new InvalidArgumentException("no gradient $name");
}

/** Rough 16-colour code for an arbitrary colour (hue buckets, keeps dim colours visible). */
function sbCode16(array $rgb, bool $bright = false): int
{
    [$r, $g, $b] = $rgb;
    $max = max($rgb);
    $min = min($rgb);
    if ($max - $min < 0.18 * max(1, $max)) {
        return $max > 200 ? 97 : ($max > 120 ? 37 : 90);
    }
    $h = rad2deg(atan2(sqrt(3) * ($g - $b), 2 * $r - $g - $b));
    $h = $h < 0 ? $h + 360 : $h;
    $base = match (true) {
        $h < 20 || $h >= 335 => 31, $h < 70 => 33, $h < 160 => 32,
        $h < 200 => 36, $h < 255 => 34, default => 35,
    };
    return $bright || $max > 190 ? $base + 60 : $base;
}

function sbPut(array &$cells, array &$pins, int $x, int $y, string $glyph, ?array $rgb, ?int $code16 = null, ?array $bg = [0, 0, 0]): void
{
    if (!isset($cells[$y][$x])) {
        return;
    }
    $cells[$y][$x] = [$glyph, $rgb, $bg];
    if ($rgb !== null) {
        $pins[implode(',', $rgb)] = $code16 ?? sbCode16($rgb);
    }
    if ($bg !== null && !isset($pins[implode(',', $bg)])) {
        $pins[implode(',', $bg)] = $bg === [0, 0, 0] ? 30 : sbCode16($bg);
    }
}

function sbBox(array &$cells, array &$pins, int $x0, int $y0, int $x1, int $y1, array $rgb, ?int $code16 = null, bool $round = true): void
{
    for ($x = $x0 + 1; $x < $x1; $x++) {
        sbPut($cells, $pins, $x, $y0, '─', $rgb, $code16);
        sbPut($cells, $pins, $x, $y1, '─', $rgb, $code16);
    }
    for ($y = $y0 + 1; $y < $y1; $y++) {
        sbPut($cells, $pins, $x0, $y, '│', $rgb, $code16);
        sbPut($cells, $pins, $x1, $y, '│', $rgb, $code16);
    }
    $c = $round ? ['╭', '╮', '╰', '╯'] : ['┌', '┐', '└', '┘'];
    sbPut($cells, $pins, $x0, $y0, $c[0], $rgb, $code16);
    sbPut($cells, $pins, $x1, $y0, $c[1], $rgb, $code16);
    sbPut($cells, $pins, $x0, $y1, $c[2], $rgb, $code16);
    sbPut($cells, $pins, $x1, $y1, $c[3], $rgb, $code16);
}

/** Resolve a run role to [rgb, code16]. */
function sbRole(string $role, array $opts = []): array
{
    $t = sbTheme();
    $line = lkHex($opts['line'] ?? $t['cpu_box']);
    return match ($role) {
        'line' => [$line, $opts['line16'] ?? 32],
        'hi' => [lkHex($t['hi_fg']), 91],
        'title' => [lkHex($t['title']), 97],
        'fg' => [lkHex($t['main_fg']), 37],
        'down' => [lkHex($t['download'][2]), 94],
        'up' => [lkHex($t['upload'][2]), 95],
        default => [lkHex($role), sbCode16(lkHex($role))],
    };
}

/** Width of a run list. */
function sbRunWidth(array $runs): int
{
    return array_sum(array_map(static fn (array $r): int => mb_strlen($r[0]), $runs));
}

function sbRuns(array &$cells, array &$pins, int $x, int $y, array $runs, array $opts = []): int
{
    foreach ($runs as [$text, $role]) {
        [$rgb, $code] = sbRole($role, $opts);
        foreach (mb_str_split($text) as $g) {
            sbPut($cells, $pins, $x++, $y, $g, $rgb, $code);
        }
    }
    return $x;
}

/** "┐¹cpu┌" tabs joined by "─" → runs (first tab opens with "╭─"). */
function sbTabs(array $labels, bool $numbered = true): array
{
    $runs = [['╭─', 'line']];
    foreach ($labels as $i => $l) {
        $runs[] = ['┐', 'line'];
        if ($numbered) {
            $runs[] = [SB_SUPERSCRIPT[$i] ?? '', 'hi'];
        }
        $runs[] = [$l, 'title'];
        $runs[] = ['┌', 'line'];
        if ($i < count($labels) - 1) {
            $runs[] = ['─', 'line'];
        }
    }
    return $runs;
}

/**
 * Outer btop panel frame over the whole grid. $spec keys: top, right,
 * botLeft, botRight (run lists). Left lists shrink (drop trailing tabs) until
 * at least opts['gap'] (default 2) line cells separate them from the right list.
 */
function sbFrame(array &$cells, array &$pins, int $w, int $h, array $spec, array $opts = []): void
{
    [$line, $code] = sbRole('line', $opts);
    sbBox($cells, $pins, 0, 0, $w - 1, $h - 1, $line, $code);
    $gap = $opts['gap'] ?? 2;
    foreach ([['top', 'right', 0], ['botLeft', 'botRight', $h - 1]] as [$lk, $rk, $y]) {
        $left = $spec[$lk] ?? [];
        $right = $spec[$rk] ?? [];
        while ($left && sbRunWidth($left) + sbRunWidth($right) + $gap > $w) {
            // drop the last tab: pop back to (and including) its opening '┐', then
            // the '─' joiner, so the list again ends on the previous tab's '┌'
            do {
                $r = array_pop($left);
            } while ($left && $r[0] !== '┐');
            if ($left && end($left)[0] === '─') {
                array_pop($left);
            }
            if (count($left) <= 1) {
                $left = [];
            }
        }
        if ($left) {
            sbRuns($cells, $pins, 0, $y, $left, $opts);
        }
        if ($right) {
            sbRuns($cells, $pins, $w - sbRunWidth($right), $y, $right, $opts);
        }
    }
}

/** One braille area cell. $levels: heights (dots from the anchor edge) of its two dot columns. */
function sbArea(array $levels, int $offset, bool $fromTop, int $totalDots): ?array
{
    $bits = 0;
    $far = -1;
    for ($k = 0; $k < 4; $k++) {
        $lvl = $fromTop ? $offset + $k : $offset + (3 - $k);
        foreach ([0, 1] as $c) {
            if ($lvl < $levels[$c]) {
                $bits |= SB_BITS[$k][$c];
                $far = max($far, $lvl);
            }
        }
    }
    return $bits === 0 ? null : [mb_chr(0x2800 + $bits), ($far + 1) / max(1, $totalDots)];
}

function sbSeries(int $n, int $seed, float $base = 0.45, float $burst = 0.55, int $smooth = 2): array
{
    static $memo = [];
    return $memo["$n/$seed/$base/$burst/$smooth"] ??= bnTraffic($n, $seed, $base, $burst, $smooth);
}

/**
 * Braille area graph in cells x0..x1 × y0..y1 (inclusive).
 * opts: fromTop (false), bright (1.0), fade (0 = none; cells over which the
 * graph darkens toward the canvas sides), canvasW (for fade), shift (0, dot
 * columns to scroll), skip (callable(cx, cy): bool), code16 (fixed code or
 * null = from the undimmed gradient colour, never black/white), blank (true:
 * clear empty cells to black).
 */
function sbGraph(array &$cells, array &$pins, int $x0, int $y0, int $x1, int $y1, array $series, array $grad, array $opts = []): void
{
    $fromTop = $opts['fromTop'] ?? false;
    $bright = $opts['bright'] ?? 1.0;
    $fade = $opts['fade'] ?? 0.0;
    $cw = $opts['canvasW'] ?? count($cells[0]);
    $shift = $opts['shift'] ?? 0;
    $skip = $opts['skip'] ?? null;
    $total = ($y1 - $y0 + 1) * 4;
    for ($cy = $y0; $cy <= $y1; $cy++) {
        for ($cx = $x0; $cx <= $x1; $cx++) {
            if ($skip !== null && $skip($cx, $cy)) {
                continue;
            }
            $lv = [];
            foreach ([0, 1] as $c) {
                $i = ($cx - $x0) * 2 + $c + $shift;
                $lv[] = (int) round(($series[$i] ?? 0.0) * $total);
            }
            $off = $fromTop ? ($cy - $y0) * 4 : ($y1 - $cy) * 4;
            $hit = sbArea($lv, $off, $fromTop, $total);
            if ($hit === null) {
                if ($opts['blank'] ?? true) {
                    sbPut($cells, $pins, $cx, $cy, ' ', null);
                }
                continue;
            }
            $full = lkGradient($grad, $hit[1]);
            $k = $bright;
            if ($fade > 0) {
                $d = min($cx, $cw - 1 - $cx);
                $k *= max(0.0, min(1.0, ($d - 1) / $fade)) ** 1.3;
            }
            $rgb = lkScale($full, $k);
            $code = $opts['code16'] ?? sbCode16($full);
            if (max($rgb) < 30) {
                $code = 30;
            } elseif (in_array($code, [37, 97, 90], true)) {
                $code = 32;
            } elseif ($bright < 0.8 && $code >= 90) {
                $code -= 60;
            }
            sbPut($cells, $pins, $cx, $cy, $hit[0], $rgb, $code);
        }
    }
}

/** btop ■ meter: filled part along grad (mid→end), rest in meter_bg. opts: glyph, empty, from (0..1 gradient start). */
function sbMeter(array &$cells, array &$pins, int $x, int $y, int $w, float $fill, array $grad, array $opts = []): void
{
    $glyph = $opts['glyph'] ?? '■';
    $lit = (int) round(max(0.0, min(1.0, $fill)) * $w);
    $from = $opts['from'] ?? 0.5;
    for ($i = 0; $i < $w; $i++) {
        if ($i < $lit) {
            $rgb = lkGradient($grad, $from + (1 - $from) * $i / max(1, $w - 1));
            $code = sbCode16($rgb, true);
            sbPut($cells, $pins, $x + $i, $y, $glyph, $rgb, $code === 97 ? 92 : $code);
        } else {
            sbPut($cells, $pins, $x + $i, $y, $opts['empty'] ?? $glyph, lkHex(sbTheme()['meter_bg']), 90);
        }
    }
}

// ================================================================ CLI demo

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    if (!$args || in_array('--help', $args, true) || in_array('-h', $args, true)) {
        preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
        echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
        exit($args ? 0 : 2);
    }
    $w = 40;
    $h = 9;
    $depth = 'tc';
    foreach ($args as $a) {
        if (preg_match('/^--demo(?:=(\d+)x(\d+))?$/', $a, $m) && isset($m[1])) {
            [$w, $h] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^--depth=(tc|256|16)$/', $a, $m)) {
            $depth = $m[1];
        }
    }
    $cells = lkBlankCells($w, $h, [0, 0, 0]);
    $pins = ['0,0,0' => 30];
    $n = $w * 2 + 8;
    sbGraph($cells, $pins, 1, 1, $w - 2, intdiv($h, 2) - 1, sbSeries($n, 11), sbGrad('upload'), ['fromTop' => true, 'bright' => 0.8, 'fade' => 6]);
    sbGraph($cells, $pins, 1, intdiv($h, 2), $w - 2, $h - 2, sbSeries($n, 7), sbGrad('cpu'), ['bright' => 0.8, 'fade' => 6]);
    $mx = intdiv($w, 2) - 6;
    sbBox($cells, $pins, $mx, 1, $mx + 12, min($h - 2, 6), lkHex('#77ca9b'), 32);
    sbRuns($cells, $pins, $mx + 2, 1, [['┐', '#77ca9b'], ['¹', 'hi'], ['cpu', 'title'], ['┌', '#77ca9b']]);
    sbMeter($cells, $pins, $mx + 1, 3, 11, 0.7, sbGrad('cpu'));
    sbMeter($cells, $pins, $mx + 1, 4, 11, 0.4, sbGrad('used'));
    sbFrame($cells, $pins, $w, $h, [
        'top' => sbTabs(['cpu', 'mem', 'net', 'proc']),
        'right' => [['┐', 'line'], ['-', 'hi'], [' 2000ms ', 'title'], ['+', 'hi'], ['┌─╮', 'line']],
        'botLeft' => [['╰─┘', 'line'], ['▼', 'down'], [' 48.2 MiB/s', 'fg'], ['└', 'line']],
        'botRight' => [['┘', 'line'], ['▲', 'up'], [' 3.1 MiB/s', 'fg'], ['└─╯', 'line']],
    ]);
    echo lkEncode($cells, $depth, $depth === '16' ? $pins : [], 'nearest');
}
