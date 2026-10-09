<?php

declare(strict_types=1);

/**
 * braille-netgraph — btop / candy-top style mirrored network graph in braille
 * (2×4 dots per cell): series A ("download") is a filled area growing UP from
 * a horizontal axis, series B ("upload") hangs DOWN from it — the layout of
 * candy-top's net panel (src/Panel/Net/NetView.php, DualSampleGraph, upload
 * inverted). No dependencies.
 *
 * API
 *   bnTraffic(n, seed, base, burst, smooth)  → list<float 0..1> synthetic traffic
 *   bnGraph(wCells, hCells, axisDot, up, down)
 *       up/down: list<float 0..1> per dot column (length ≥ 2*wCells; extra ignored),
 *       scaled to the space above / below axisDot (dot row, 0 = top).
 *     → [cy][cx] = null | ['glyph'=>braille, 'series'=>'up'|'down', 't'=>0..1]
 *       t = how far the cell's outermost lit dot is from the axis (0 at the
 *       axis, 1 at the edge) — btop colours graph rows by height with the
 *       theme's *_start → *_mid → *_end gradient, so feed t to that.
 */

const BN_BITS = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];

/** Seeded smooth "traffic": slow swell + a few bursts, smoothed, 0..1. */
function bnTraffic(int $n, int $seed, float $base = 0.35, float $burst = 0.6, int $smooth = 3): array
{
    $h = static function (int $i, int $s): float {
        $x = ($i * 374761393 + $s * 668265263) & 0xFFFFFFFF;
        $x = (($x ^ ($x >> 13)) * 1274126177) & 0xFFFFFFFF;
        return (($x ^ ($x >> 16)) & 0xFFFFFF) / 0x1000000;
    };
    $raw = [];
    $ph = $h(1, $seed) * 6.28;
    for ($i = 0; $i < $n; $i++) {
        $v = $base * (0.55 + 0.45 * sin($i / 9.0 + $ph)) * (0.7 + 0.3 * sin($i / 3.1 + 2 * $ph));
        $v += $h($i, $seed) * 0.18;
        if ($h(intdiv($i, 7), $seed + 99) > 0.72) {        // bursty stretches
            $v += $burst * $h(intdiv($i, 7), $seed + 5);
        }
        $raw[] = $v;
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $s = 0.0;
        $k = 0;
        for ($j = $i - $smooth; $j <= $i + $smooth; $j++) {
            if (isset($raw[$j])) {
                $s += $raw[$j];
                $k++;
            }
        }
        $out[] = max(0.0, min(1.0, $s / $k));
    }
    return $out;
}

function bnGraph(int $wCells, int $hCells, int $axisDot, array $up, array $down): array
{
    $hDots = $hCells * 4;
    $lit = [];
    for ($x = 0; $x < $wCells * 2; $x++) {
        $u = (int) round(($up[$x] ?? 0) * $axisDot);
        for ($k = 0; $k < $u; $k++) {
            $lit[$axisDot - 1 - $k][$x] = ['up', ($k + 1) / max(1, $axisDot)];
        }
        $span = $hDots - $axisDot;
        $d = (int) round(($down[$x] ?? 0) * $span);
        for ($k = 0; $k < $d; $k++) {
            $lit[$axisDot + $k][$x] = ['down', ($k + 1) / max(1, $span)];
        }
    }
    $cells = [];
    for ($cy = 0; $cy < $hCells; $cy++) {
        for ($cx = 0; $cx < $wCells; $cx++) {
            $bits = 0;
            $t = 0.0;
            $series = [];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $p = $lit[$cy * 4 + $dy][$cx * 2 + $dx] ?? null;
                    if ($p !== null) {
                        $bits |= BN_BITS[$dy][$dx];
                        $t = max($t, $p[1]);
                        $series[$p[0]] = ($series[$p[0]] ?? 0) + 1;
                    }
                }
            }
            if ($bits === 0) {
                $cells[$cy][$cx] = null;
                continue;
            }
            arsort($series);
            $cells[$cy][$cx] = ['glyph' => mb_chr(0x2800 + $bits, 'UTF-8'), 'series' => array_key_first($series), 't' => $t];
        }
    }
    return $cells;
}
