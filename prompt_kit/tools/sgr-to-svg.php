<?php

declare(strict_types=1);

/**
 * Cell-grid SGR → SVG renderer for theme previews (truecolor + 16/256 basic).
 *
 *   php prompt_kit/tools/sgr-to-svg.php in.sgr out.svg [--term-bg=#rrggbb] [--term-fg=#rrggbb]
 *   rsvg-convert out.svg -o out.png
 *
 * Every cell is placed at an explicit x so box-drawing and braille line up
 * whatever the font's advance; default fg/bg are the "terminal" colours.
 */

$in = $argv[1] ?? 'php://stdin';
$out = $argv[2] ?? 'php://stdout';
$termBg = '#0c0c10';
$termFg = '#d0d0d0';
foreach (array_slice($argv, 3) as $a) {
    if (str_starts_with($a, '--term-bg=')) {
        $termBg = substr($a, 10);
    } elseif (str_starts_with($a, '--term-fg=')) {
        $termFg = substr($a, 10);
    }
}

const CW = 9.0;
const CH = 19.0;
const BASIC = [
    '#000000', '#cd3131', '#0dbc79', '#e5e510', '#2472c8', '#bc3fbc', '#11a8cd', '#e5e5e5',
    '#666666', '#f14c4c', '#23d18b', '#f5f543', '#3b8eea', '#d670d6', '#29b8db', '#ffffff',
];

function c256(int $n): string
{
    if ($n < 16) {
        return BASIC[$n];
    }
    if ($n >= 232) {
        $v = 8 + ($n - 232) * 10;
        return sprintf('#%02x%02x%02x', $v, $v, $v);
    }
    $n -= 16;
    $l = [0, 95, 135, 175, 215, 255];
    return sprintf('#%02x%02x%02x', $l[intdiv($n, 36)], $l[intdiv($n, 6) % 6], $l[$n % 6]);
}

$text = (string) file_get_contents($in);
$lines = explode("\n", rtrim($text, "\n"));
$fg = null;
$bg = null;
$bold = false;
$cells = [];
foreach ($lines as $row => $line) {
    $col = 0;
    $parts = preg_split('/(\e\[[0-9;:]*[A-Za-z])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        if ($p[0] === "\e") {
            if (!str_ends_with($p, 'm')) {
                continue;
            }
            $ps = array_map('intval', explode(';', substr($p, 2, -1) === '' ? '0' : substr($p, 2, -1)));
            for ($i = 0; $i < count($ps); $i++) {
                $v = $ps[$i];
                if ($v === 0) {
                    $fg = $bg = null;
                    $bold = false;
                } elseif ($v === 1) {
                    $bold = true;
                } elseif ($v === 22) {
                    $bold = false;
                } elseif ($v >= 30 && $v <= 37) {
                    $fg = BASIC[$v - 30];
                } elseif ($v >= 90 && $v <= 97) {
                    $fg = BASIC[$v - 82];
                } elseif ($v >= 40 && $v <= 47) {
                    $bg = BASIC[$v - 40];
                } elseif ($v >= 100 && $v <= 107) {
                    $bg = BASIC[$v - 92];
                } elseif ($v === 39) {
                    $fg = null;
                } elseif ($v === 49) {
                    $bg = null;
                } elseif ($v === 38 || $v === 48) {
                    $mode = $ps[$i + 1] ?? 0;
                    if ($mode === 2) {
                        $c = sprintf('#%02x%02x%02x', $ps[$i + 2] ?? 0, $ps[$i + 3] ?? 0, $ps[$i + 4] ?? 0);
                        $i += 4;
                    } else {
                        $c = c256($ps[$i + 2] ?? 0);
                        $i += 2;
                    }
                    $v === 38 ? $fg = $c : $bg = $c;
                }
            }
            continue;
        }
        foreach (mb_str_split($p) as $ch) {
            $w = max(1, mb_strwidth($ch));
            $cells[$row][$col] = [$ch, $fg, $bg, $bold];
            $col += $w;
        }
    }
}

$rows = count($lines);
$cols = 0;
foreach ($cells as $r) {
    $cols = max($cols, (int) max(array_keys($r)) + 1);
}
$W = $cols * CW;
$H = $rows * CH;
$o = [];
$o[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %.1f %.1f">', $W, $H, $W, $H);
$o[] = sprintf('<rect width="100%%" height="100%%" fill="%s"/>', $termBg);
$o[] = '<g shape-rendering="crispEdges">';
foreach ($cells as $r => $row) {
    foreach ($row as $c => [$ch, $f, $b, $bd]) {
        if ($b !== null) {
            $o[] = sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $c * CW, $r * CH, CW + 0.6, CH + 0.6, $b);
        }
    }
}
$o[] = '</g><g font-family="DejaVu Sans Mono" font-size="15">';
foreach ($cells as $r => $row) {
    foreach ($row as $c => [$ch, $f, $b, $bd]) {
        if ($ch === ' ') {
            continue;
        }
        $code = mb_ord($ch);
        // Box drawing / blocks: draw as stretched glyphs so lines join between cells.
        $o[] = sprintf(
            '<text x="%.1f" y="%.1f" fill="%s"%s>%s</text>',
            $c * CW,
            $r * CH + 14.5,
            $f ?? $termFg,
            $bd ? ' font-weight="bold"' : '',
            htmlspecialchars($ch, ENT_XML1),
        );
        unset($code);
    }
}
$o[] = '</g></svg>';
file_put_contents($out, implode("\n", $o));
