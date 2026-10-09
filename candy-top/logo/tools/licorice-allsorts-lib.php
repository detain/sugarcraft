<?php

declare(strict_types=1);

/**
 * Reusable half-block ANSI logo helpers (licorice-allsorts designer).
 *
 *  - encode_halfblocks(): pixel grid (rows of ?[r,g,b]) -> tc ANSI lines,
 *    two pixel rows per terminal row, transparent where a pixel is null.
 *  - downgrade_ansi():    rewrite every 38;2/48;2 SGR in a tc file to 256 or 16 colours.
 *  - verify_ansi():       strip SGR, assert every line has the same mb_strwidth.
 */

/** @param list<list<?array{int,int,int}>> $px */
function encode_halfblocks(array $px): string
{
    $h = count($px);
    if ($h % 2 === 1) {
        $px[] = array_fill(0, count($px[0]), null);
        $h++;
    }
    $w = count($px[0]);
    $out = [];
    for ($r = 0; $r < $h; $r += 2) {
        $line = '';
        $state = '';
        for ($x = 0; $x < $w; $x++) {
            $t = $px[$r][$x];
            $b = $px[$r + 1][$x];
            if ($t === null && $b === null) {
                $sgr = '0';
                $ch = ' ';
            } elseif ($b === null) {
                $sgr = '0;' . tc_fg($t);
                $ch = '▀';
            } elseif ($t === null) {
                $sgr = '0;' . tc_fg($b);
                $ch = '▄';
            } elseif ($t === $b) {
                $sgr = '0;' . tc_fg($t);
                $ch = '█';
            } else {
                $sgr = '0;' . tc_fg($t) . ';' . tc_bg($b);
                $ch = '▀';
            }
            if ($sgr !== $state) {
                $line .= "\e[{$sgr}m";
                $state = $sgr;
            }
            $line .= $ch;
        }
        $out[] = $line . "\e[0m";
    }
    return implode("\n", $out) . "\n";
}

function tc_fg(array $c): string { return "38;2;{$c[0]};{$c[1]};{$c[2]}"; }
function tc_bg(array $c): string { return "48;2;{$c[0]};{$c[1]};{$c[2]}"; }

function hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function shade_rgb(array $c, float $f): array
{
    return array_map(static fn ($v) => max(0, min(255, (int) round($f >= 0 ? $v + (255 - $v) * $f : $v * (1 + $f)))), $c);
}

/** Perceptual-ish "redmean" distance. */
function rgb_dist(array $a, array $b): float
{
    $rm = ($a[0] + $b[0]) / 2;
    $dr = $a[0] - $b[0]; $dg = $a[1] - $b[1]; $db = $a[2] - $b[2];
    return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
}

/** xterm 256 palette, index => rgb (only 16..255 used as targets). */
function xterm256(): array
{
    static $p = null;
    if ($p !== null) { return $p; }
    $p = [];
    $lv = [0, 95, 135, 175, 215, 255];
    for ($i = 0; $i < 216; $i++) {
        $p[16 + $i] = [$lv[intdiv($i, 36)], $lv[intdiv($i, 6) % 6], $lv[$i % 6]];
    }
    for ($i = 0; $i < 24; $i++) {
        $g = 8 + $i * 10;
        $p[232 + $i] = [$g, $g, $g];
    }
    return $p;
}

/** The 16 ANSI colours (xterm defaults), keyed by fg SGR code. */
function ansi16(): array
{
    return [
        30 => [0, 0, 0], 31 => [205, 0, 0], 32 => [0, 205, 0], 33 => [205, 205, 0],
        34 => [0, 0, 238], 35 => [205, 0, 205], 36 => [0, 205, 205], 37 => [229, 229, 229],
        90 => [127, 127, 127], 91 => [255, 0, 0], 92 => [0, 255, 0], 93 => [255, 255, 0],
        94 => [92, 92, 255], 95 => [255, 0, 255], 96 => [0, 255, 255], 97 => [255, 255, 255],
    ];
}

function nearest(array $rgb, array $pal): int
{
    $best = 0; $bd = INF;
    foreach ($pal as $k => $c) {
        $d = rgb_dist($rgb, $c);
        if ($d < $bd) { $bd = $d; $best = $k; }
    }
    return $best;
}

/**
 * Downgrade a truecolor ANSI string. $depth: 256 | 16.
 * $pin16 optionally maps 'r,g,b' => fg code (30-37/90-97) to override nearest for the 16 palette.
 */
function downgrade_ansi(string $ansi, int $depth, array $pin16 = []): string
{
    return preg_replace_callback('/([34])8;2;(\d+);(\d+);(\d+)/', static function ($m) use ($depth, $pin16) {
        $rgb = [(int) $m[2], (int) $m[3], (int) $m[4]];
        $bg = $m[1] === '4';
        if ($depth === 256) {
            return ($bg ? '48' : '38') . ';5;' . nearest($rgb, xterm256());
        }
        $code = $pin16[implode(',', $rgb)] ?? nearest($rgb, ansi16());
        return (string) ($bg ? $code + 10 : $code);
    }, $ansi);
}

/** Returns [width, height]; throws if any line is ragged or lacks a trailing reset. */
function verify_ansi(string $ansi): array
{
    $lines = explode("\n", rtrim($ansi, "\n"));
    $widths = [];
    foreach ($lines as $i => $l) {
        if (!str_ends_with($l, "\e[0m")) {
            throw new RuntimeException("line $i lacks trailing reset");
        }
        $plain = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $l);
        $widths[] = mb_strwidth($plain, 'UTF-8');
    }
    if (count(array_unique($widths)) !== 1) {
        throw new RuntimeException('ragged widths: ' . implode(',', $widths));
    }
    return [$widths[0], count($lines)];
}
