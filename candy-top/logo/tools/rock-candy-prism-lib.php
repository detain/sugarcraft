<?php

declare(strict_types=1);

/**
 * rock-candy-prism logo toolkit — generic, design-agnostic helpers.
 *
 * Pipeline: pixel grid (rgb|null per pixel, 2 pixels per terminal row)
 *   → rcpHalfBlock()  → cell grid [ch, fg rgb|null, bg rgb|null]
 *   → rcpEncode()     → ANSI text at depth 'tc' | '256' | '16'
 *   → rcpVerify()     → equal visible widths + trailing \e[0m per line.
 *
 * Colours are [r,g,b] int arrays. Cell grids may be extended with plain
 * text rows (sparkles, captions) via rcpTextRow().
 */

/** @return int[] */
function rcpHex(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Linear blend a→b by t∈[0,1]. */
function rcpMix(array $a, array $b, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    return [
        (int) round($a[0] + ($b[0] - $a[0]) * $t),
        (int) round($a[1] + ($b[1] - $a[1]) * $t),
        (int) round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

function rcpScale(array $c, float $k): array
{
    return array_map(static fn (int $v): int => (int) max(0, min(255, round($v * $k))), $c);
}

/**
 * Turn a pixel grid (rows of rgb|null, even row count) into a cell grid
 * using upper/lower half blocks: fg = top pixel, bg = bottom pixel.
 *
 * @param array<int, array<int, ?array>> $px
 * @return array<int, array<int, array{0:string,1:?array,2:?array}>>
 */
function rcpHalfBlock(array $px): array
{
    $cells = [];
    $h = count($px);
    $w = count($px[0]);
    for ($y = 0; $y < $h; $y += 2) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $t = $px[$y][$x] ?? null;
            $b = $px[$y + 1][$x] ?? null;
            if ($t === null && $b === null) {
                $row[] = [' ', null, null];
            } elseif ($b === null) {
                $row[] = ['▀', $t, null];
            } elseif ($t === null) {
                $row[] = ['▄', $b, null];
            } elseif ($t === $b) {
                $row[] = ['█', $t, null];
            } else {
                $row[] = ['▀', $t, $b];
            }
        }
        $cells[] = $row;
    }
    return $cells;
}

/**
 * Build a cell row of width $w from sparse glyph placements.
 *
 * @param array<int, array{0:string,1:array}> $glyphs col => [glyph, rgb]
 */
function rcpTextRow(int $w, array $glyphs): array
{
    $row = array_fill(0, $w, [' ', null, null]);
    foreach ($glyphs as $x => [$g, $c]) {
        if ($x >= 0 && $x < $w) {
            $row[$x] = [$g, $c, null];
        }
    }
    return $row;
}

// ---------------------------------------------------------------- depth

/** xterm 256 palette as rgb list (16..255 used for matching). */
function rcpXterm256(): array
{
    static $pal = null;
    if ($pal !== null) {
        return $pal;
    }
    $pal = [];
    $steps = [0, 95, 135, 175, 215, 255];
    for ($i = 0; $i < 216; $i++) {
        $pal[16 + $i] = [$steps[intdiv($i, 36)], $steps[intdiv($i, 6) % 6], $steps[$i % 6]];
    }
    for ($i = 0; $i < 24; $i++) {
        $v = 8 + $i * 10;
        $pal[232 + $i] = [$v, $v, $v];
    }
    return $pal;
}

function rcpTo256(array $c): int
{
    $best = 16;
    $bd = PHP_INT_MAX;
    foreach (rcpXterm256() as $n => $p) {
        // weighted distance keeps pastel hues from collapsing to grey
        $d = 2 * ($c[0] - $p[0]) ** 2 + 4 * ($c[1] - $p[1]) ** 2 + 3 * ($c[2] - $p[2]) ** 2;
        if ($d < $bd) {
            $bd = $d;
            $best = $n;
        }
    }
    return $best;
}

/**
 * Hue-preserving 16-colour reduction. Returns the SGR fg code (30-37/90-97).
 * Very pale → bright white, very dark → bright black, otherwise hue bucket,
 * bright when value is high.
 */
function rcpTo16(array $c): int
{
    [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $v = $max;
    $s = $max == 0.0 ? 0.0 : ($max - $min) / $max;
    if ($v < 0.30) {
        return 90;
    }
    if ($s < 0.25) {
        return $v > 0.75 ? 97 : 37;
    }
    $d = $max - $min;
    if ($max == $r) {
        $h = 60 * fmod(($g - $b) / $d, 6);
    } elseif ($max == $g) {
        $h = 60 * (($b - $r) / $d + 2);
    } else {
        $h = 60 * (($r - $g) / $d + 4);
    }
    if ($h < 0) {
        $h += 360;
    }
    $base = match (true) {
        $h < 15 || $h >= 345 => 31,
        $h < 70 => 33,
        $h < 160 => 32,
        $h < 200 => 36,
        $h < 255 => 34,
        default => 35,
    };
    return $v > 0.62 ? $base + 60 : $base;
}

function rcpFg(array $c, string $depth): string
{
    return match ($depth) {
        'tc' => "38;2;{$c[0]};{$c[1]};{$c[2]}",
        '256' => '38;5;' . rcpTo256($c),
        '16' => (string) rcpTo16($c),
    };
}

function rcpBg(?array $c, string $depth): string
{
    if ($c === null) {
        return '49';
    }
    return match ($depth) {
        'tc' => "48;2;{$c[0]};{$c[1]};{$c[2]}",
        '256' => '48;5;' . rcpTo256($c),
        '16' => (string) (rcpTo16($c) + 10),
    };
}

/**
 * Encode a cell grid. Emits SGR only on state change; each line ends \e[0m.
 */
function rcpEncode(array $cells, string $depth): string
{
    $out = '';
    foreach ($cells as $row) {
        $curFg = null;
        $curBg = '49';
        $line = '';
        foreach ($row as [$ch, $fg, $bg]) {
            $bgCode = rcpBg($bg, $depth);
            $codes = [];
            if ($bgCode !== $curBg) {
                $codes[] = $bgCode;
                $curBg = $bgCode;
            }
            if ($ch !== ' ' && $fg !== null) {
                $fgCode = rcpFg($fg, $depth);
                if ($fgCode !== $curFg) {
                    $codes[] = $fgCode;
                    $curFg = $fgCode;
                }
            }
            if ($codes) {
                $line .= "\e[" . implode(';', $codes) . 'm';
            }
            $line .= $ch;
        }
        $out .= $line . "\e[0m\n";
    }
    return $out;
}

// ---------------------------------------------------------------- verify

function rcpStrip(string $s): string
{
    return preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $s);
}

/**
 * Check every line has the same visible width and ends with \e[0m.
 * Returns [width, height]; throws on violation.
 *
 * @return array{0:int,1:int}
 */
function rcpVerify(string $ansi, string $label = 'logo'): array
{
    $lines = explode("\n", rtrim($ansi, "\n"));
    $widths = [];
    foreach ($lines as $i => $l) {
        if (!str_ends_with($l, "\e[0m")) {
            throw new RuntimeException("$label: line $i does not end with \\e[0m");
        }
        $widths[] = mb_strwidth(rcpStrip($l));
    }
    if (count(array_unique($widths)) !== 1) {
        throw new RuntimeException("$label: ragged widths " . implode(',', $widths));
    }
    return [$widths[0], count($lines)];
}
