<?php

declare(strict_types=1);

/**
 * braille-canvas — standalone braille (U+2800) dot-canvas rasteriser.
 *
 * Each terminal cell is a 2×4 dot matrix, so a W×H cell canvas gives
 * (2W)×(4H) dots. Every lit dot carries a LAYER id (what it belongs to:
 * letter ink, a waveform, a grid, …), a SEQUENCE number (the order the
 * "pen" drew it) and a STROKE id (which polyline drew it), so callers can:
 *   - colour a cell by its dominant layer and dot density,
 *   - animate drawing by only revealing dots with seq ≤ t (beam / pen sweep),
 *   - draw a background layer (grid) only into otherwise empty cells,
 *   - find junction cells where ≥2 strokes meet (corners, crossings → flare).
 *
 * No dependencies; pairs naturally with logo-kit.php (cells → ANSI).
 *
 * API
 *   bcNew(wCells, hCells)                       → canvas
 *   bcSet(&c, x, y, layer, seq, overwrite)      plot one dot
 *   bcLine(&c, x0,y0,x1,y1, layer, &seq, pen)   thick line (pen = square stamp size)
 *   bcPolyline(&c, pts, layer, &seq, pen)       connected points [[x,y],…]
 *   bcArc(cx,cy,rx,ry,a0,a1,steps)              → points along an ellipse arc (deg, CCW, y-up)
 *   bcStrokeFont(&c, font, text, x, y, layer, &seq, pen, advance, wordGap)
 *                                               lay out a vector stroke font
 *   bcCells(c, ?filter)                         → [cy][cx] = [char, count, layers[id=>n], minSeq, maxSeq, strokes]
 *   bcChar(bits)                                → braille glyph for an 8-bit dot mask
 */

/** Bit for dot (dx, dy) inside a cell, Unicode braille numbering. */
const BC_BITS = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];

function bcNew(int $wCells, int $hCells): array
{
    return ['w' => $wCells * 2, 'h' => $hCells * 4, 'cw' => $wCells, 'ch' => $hCells, 'dots' => [], 'stroke' => 0];
}

/** Plot one dot. By default the first writer wins (keeps earliest seq). */
function bcSet(array &$c, int $x, int $y, int $layer, int $seq = 0, bool $overwrite = false): void
{
    if ($x < 0 || $y < 0 || $x >= $c['w'] || $y >= $c['h']) {
        return;
    }
    if (!$overwrite && isset($c['dots'][$y][$x])) {
        return;
    }
    $c['dots'][$y][$x] = [$layer, $seq, $c['stroke']];
}

/** Thick line: samples every ≤0.5 dot and stamps a pen×pen square at each point. */
function bcLine(array &$c, float $x0, float $y0, float $x1, float $y1, int $layer, int &$seq, int $pen = 1): void
{
    $n = max(1, (int) ceil(max(abs($x1 - $x0), abs($y1 - $y0)) * 2));
    for ($i = 0; $i <= $n; $i++) {
        $t = $i / $n;
        $px = (int) round($x0 + ($x1 - $x0) * $t);
        $py = (int) round($y0 + ($y1 - $y0) * $t);
        for ($dy = 0; $dy < $pen; $dy++) {
            for ($dx = 0; $dx < $pen; $dx++) {
                bcSet($c, $px + $dx, $py + $dy, $layer, $seq);
            }
        }
        $seq++;
    }
}

/** Draws one stroke (new stroke id) through the points. */
function bcPolyline(array &$c, array $pts, int $layer, int &$seq, int $pen = 1): void
{
    $c['stroke']++;
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        bcLine($c, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $layer, $seq, $pen);
    }
}

/** Points on an ellipse arc; angles in degrees, 0 = right, 90 = up (screen y flipped). */
function bcArc(float $cx, float $cy, float $rx, float $ry, float $a0, float $a1, int $steps = 24): array
{
    $pts = [];
    for ($i = 0; $i <= $steps; $i++) {
        $a = deg2rad($a0 + ($a1 - $a0) * $i / $steps);
        $pts[] = [$cx + $rx * cos($a), $cy - $ry * sin($a)];
    }
    return $pts;
}

/**
 * Lay out text in a stroke font: font[char] = list of polylines (each a list
 * of [x,y] in glyph units, or ['arc', cx,cy,rx,ry,a0,a1] which expands via
 * bcArc). ' ' advances by $wordGap. Returns the x after the last glyph.
 */
function bcStrokeFont(array &$c, array $font, string $text, float $x, float $y, int $layer, int &$seq, int $pen, float $advance, float $wordGap): float
{
    $end = $x;
    foreach (str_split($text) as $ch) {
        if ($ch === ' ') {
            $x += $wordGap;
            continue;
        }
        foreach ($font[$ch] ?? [] as $stroke) {
            $pts = [];
            foreach ($stroke as $p) {
                if (($p[0] ?? null) === 'arc') {
                    array_push($pts, ...bcArc(...array_slice($p, 1)));
                } else {
                    $pts[] = $p;
                }
            }
            $pts = array_map(static fn (array $p): array => [$x + $p[0], $y + $p[1]], $pts);
            bcPolyline($c, $pts, $layer, $seq, $pen);
        }
        $end = $x + $advance;
        $x += $advance;
    }
    return $end;
}

function bcChar(int $bits): string
{
    return mb_chr(0x2800 + $bits, 'UTF-8');
}

/**
 * Collapse to cells. $filter(layer, seq): bool decides whether a dot is
 * visible (null = all). Each cell: [char, count, layers, minSeq, maxSeq,
 * strokes] where strokes = number of distinct stroke ids in the cell.
 */
function bcCells(array $c, ?callable $filter = null): array
{
    $out = [];
    for ($cy = 0; $cy < $c['ch']; $cy++) {
        for ($cx = 0; $cx < $c['cw']; $cx++) {
            $bits = 0;
            $count = 0;
            $layers = [];
            $min = PHP_INT_MAX;
            $max = -1;
            $strokes = [];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $d = $c['dots'][$cy * 4 + $dy][$cx * 2 + $dx] ?? null;
                    if ($d === null || ($filter !== null && !$filter($d[0], $d[1]))) {
                        continue;
                    }
                    $bits |= BC_BITS[$dy][$dx];
                    $count++;
                    $layers[$d[0]] = ($layers[$d[0]] ?? 0) + 1;
                    $min = min($min, $d[1]);
                    $max = max($max, $d[1]);
                    $strokes[$d[2]] = true;
                }
            }
            $out[$cy][$cx] = [$count ? bcChar($bits) : ' ', $count, $layers, $min, $max, count($strokes)];
        }
    }
    return $out;
}
