<?php

declare(strict_types=1);

/**
 * generate-stained-glass-candy-skinny — the ≤40-col SKINNY variant of
 * generate-stained-glass-candy.php: same leaded candy-glass window, but with
 * narrower 6-7×8 px glass masks (2-px strokes) and CANDY stacked over a
 * centred TOP sharing one lead came, so it fits 37×10 cells.
 *
 * Each letter is a glass mask cut into panes by a seeded Voronoi split; the
 * pane borders and the letter outlines become dark lead "came". Panes take
 * jewel-toned hard-candy hues (graph-coloured so neighbours never match),
 * lit by sunlight from the upper left, with a soft bevel per pane and a
 * hammered-glass hash texture. Below the window, a blurred pool of coloured
 * light spills onto the sill. Optional animation: a warm glint sweeps across.
 *
 * Reusable bits (swap as data): FONT (glass masks), PALETTE, LEAD colours,
 * PANE_SPACING / SEED (Voronoi density + layout), light + spill constants.
 * Generic helpers: sgVoronoi() (mask → region map + lead), sgColourPanes()
 * (adjacency graph colouring), sgSpill() (column-average blurred spill).
 *
 * Usage:
 *   php generate-stained-glass-candy-skinny.php            preview tc, 256, 16 (+ verify)
 *   php generate-stained-glass-candy-skinny.php --write    write .ansi files + logos.jsonl
 *   php generate-stained-glass-candy-skinny.php --regions  dump the pane map (debug)
 * Play the animation: php logo-play.php ../logo-stained-glass-candy-skinny-anim-tc-37x10.ansi 100
 */

require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'stained-glass-candy-skinny';
/** Stacked lines, each centred on the widest. */
const LINES = ['CANDY', 'TOP'];

/** Glass masks, 6×8 px (N 7×8), 2-px strokes ('#' = glass). */
const FONT = [
    'C' => ['.#####', '######', '##....', '##....', '##....', '##....', '######', '.#####'],
    'A' => ['.####.', '######', '##..##', '##..##', '######', '######', '##..##', '##..##'],
    'N' => ['###..##', '###..##', '####.##', '##.#.##', '##.#.##', '##.####', '##..###', '##..###'],
    'D' => ['#####.', '######', '##..##', '##..##', '##..##', '##..##', '######', '#####.'],
    'Y' => ['##..##', '##..##', '##..##', '######', '.####.', '..##..', '..##..', '..##..'],
    'T' => ['######', '######', '..##..', '..##..', '..##..', '..##..', '..##..', '..##..'],
    'O' => ['.####.', '######', '##..##', '##..##', '##..##', '##..##', '######', '.####.'],
    'P' => ['#####.', '######', '##..##', '##..##', '######', '#####.', '##....', '##....'],
];

/** Hard-candy glass hues. */
const PALETTE = [
    'cherry' => '#D7263D', 'tangerine' => '#F46036', 'lemon' => '#F7C548',
    'lime' => '#7BC950', 'mint' => '#1FB5A8', 'blueberry' => '#2E86DE',
    'grape' => '#8E44AD', 'bubblegum' => '#FF6FB5',
];

/** Base hue per letter (index into PALETTE, in TEXT order without spaces). */
const LETTER_HUES = [0, 1, 2, 3, 4, 5, 6, 7];

/**
 * Per-pane variants of the letter hue, so a letter stays one candy colour
 * family while its panes visibly differ: [shade (+ tint / − shade), share
 * of the NEXT palette hue mixed in].
 */
const PANE_VARIANTS = [[0.0, 0.0], [0.22, 0.0], [-0.22, 0.0], [0.05, 0.38], [-0.08, -0.38]];

const LEAD = '#1C1714';          // came
const LEAD_LIT = '#3B302A';      // internal pane came (a touch lighter than the outline)
const GLINT = '#FFF6E0';         // animation sun glint
const GLINT_LEAD = '#C9A227';    // lead catching the glint
const PANE_SPACING = 4.2;        // min distance between Voronoi seeds (px)
const SEED = 1957;
const TEXTURE = 0.07;            // ± hammered-glass brightness jitter (tc/256)
const SPILL = [0.50];         // one sill row in the skinny cut (keeps 10 rows)      // spill brightness for the two sill pixel rows
const SPILL_BLUR = 2;            // box-blur radius (px)

/** 16-colour pins: lead → black, lit lead → dark grey. */
const PINS16 = [LEAD => 30, LEAD_LIT => 30, GLINT_LEAD => 93];

const DESCRIPTION = 'Skinny 37-col cut of stained-glass-candy: CANDY stacked over a centred TOP, narrow 2-px-stroke letters as a leaded stained-glass window: chunky pixel letters cut into Voronoi panes of jewel-toned hard-candy glass (cherry, tangerine, lemon, lime, blueberry, grape, bubblegum) held by dark lead came, lit from the upper left with per-pane bevels and hammered-glass texture, and a blurred pool of coloured light spilling onto the sill below.';
const TAGS = ['skinny', 'stacked-words', 'stained-glass', 'leaded-panes', 'voronoi-panes', 'jewel-tones', 'half-block-pixels', 'light-spill', 'hammered-texture', 'sunlit'];

const ANIM_FRAMES = 20;          // 20 × 100 ms ≈ 2.0 s

// ================================================================ GENERIC HELPERS

/**
 * Voronoi pane split of a mask. Seeds are glass pixels taken in hashed order
 * and kept if ≥ $spacing from every earlier seed of the same letter, so panes
 * never straddle letters. A glass pixel whose right/down glass neighbour lies
 * in another pane becomes lead (1-px came).
 *
 * @param list<string> $mask '#' = glass
 * @param list<array{int,int,2?:int,3?:int}> $ranges letter boxes [x0, x1, y0?, y1?)
 * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, bool>>}
 *         [region id per glass pixel (y → x → id), lead flags]
 */
function sgVoronoi(array $mask, array $ranges, float $spacing, int $seed): array
{
    $h = count($mask);
    $region = [];
    $lead = [];
    $nextId = 0;
    foreach ($ranges as $li => $rg) {
        [$x0, $x1] = $rg;
        $pix = [];
        for ($y = $rg[2] ?? 0; $y < ($rg[3] ?? $h); $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($mask[$y][$x] === '#') {
                    $pix[] = [$x, $y, lkNoise($x, $y, $seed + $li)];
                }
            }
        }
        usort($pix, static fn ($a, $b) => $a[2] <=> $b[2]);
        $seeds = [];
        foreach ($pix as [$x, $y]) {
            $ok = true;
            foreach ($seeds as [$sx, $sy]) {
                if (hypot($x - $sx, $y - $sy) < $spacing) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $seeds[] = [$x, $y, $nextId++];
            }
        }
        foreach ($pix as [$x, $y]) {
            $best = INF;
            foreach ($seeds as [$sx, $sy, $id]) {
                // Slight anisotropy so cuts run across strokes, not along them.
                $d = hypot(($x - $sx) * 1.15, $y - $sy);
                if ($d < $best) {
                    $best = $d;
                    $region[$y][$x] = $id;
                }
            }
        }
    }
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            foreach ([[1, 0], [0, 1]] as [$dx, $dy]) {
                $n = $region[$y + $dy][$x + $dx] ?? null;
                if ($n !== null && $n !== $id) {
                    $lead[$y][$x] = true;
                }
            }
        }
    }
    // Panes reduced to a sliver (< 3 px of glass) are absorbed into lead.
    $count = [];
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            if (empty($lead[$y][$x])) {
                $count[$id] = ($count[$id] ?? 0) + 1;
            }
        }
    }
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            if (($count[$id] ?? 0) < 3) {
                $lead[$y][$x] = true;
            }
        }
    }
    return [$region, $lead];
}

/**
 * Assign palette indices to panes so panes touching across a lead line
 * (within 2 px) differ; ties broken by seeded hash for variety.
 * @return array<int, int> region id → palette index
 */
function sgColourPanes(array $region, array $lead, int $nColours, int $seed): array
{
    $adj = [];
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $n = $region[$y + $dy][$x + $dx] ?? null;
                    if ($n !== null && $n !== $id) {
                        $adj[$id][$n] = true;
                    }
                }
            }
        }
    }
    $ids = array_unique(array_merge(...array_map('array_values', $region)));
    sort($ids);
    $colour = [];
    $used = array_fill(0, $nColours, 0);
    foreach ($ids as $id) {
        $banned = [];
        foreach (array_keys($adj[$id] ?? []) as $n) {
            if (isset($colour[$n])) {
                $banned[$colour[$n]] = true;
            }
        }
        $best = null;
        $bestScore = INF;
        for ($c = 0; $c < $nColours; $c++) {
            if (isset($banned[$c])) {
                continue;
            }
            $score = $used[$c] + lkNoise($id, $c, $seed);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        $colour[$id] = $best ?? ($id % $nColours);
        $used[$colour[$id]]++;
    }
    return $colour;
}

/**
 * Light spill under the window: per-column average of the glass colours
 * above, box-blurred horizontally; null where no glass is near.
 * @param list<list<?array>> $glass pixel rows holding only glass colours
 * @return list<?array> per-column spill colour at full strength
 */
function sgSpill(array $glass, int $radius): array
{
    $w = count($glass[0]);
    $avg = [];
    for ($x = 0; $x < $w; $x++) {
        $sum = [0, 0, 0];
        $n = 0;
        foreach ($glass as $row) {
            if ($row[$x] !== null) {
                $sum = [$sum[0] + $row[$x][0], $sum[1] + $row[$x][1], $sum[2] + $row[$x][2]];
                $n++;
            }
        }
        $avg[$x] = $n ? [$sum, $n] : null;
    }
    $out = [];
    for ($x = 0; $x < $w; $x++) {
        $sum = [0, 0, 0];
        $n = 0;
        for ($k = -$radius; $k <= $radius; $k++) {
            if (($a = $avg[$x + $k] ?? null) !== null) {
                $sum = [$sum[0] + $a[0][0], $sum[1] + $a[0][1], $sum[2] + $a[0][2]];
                $n += $a[1];
            }
        }
        $out[$x] = $n ? array_map(static fn ($v) => (int) round($v / $n), $sum) : null;
    }
    return $out;
}

/** Pane colour: letter hue, shaded and/or mixed toward the neighbouring hue. */
function sgPaneColour(array $pal, int $hue, array $variant): array
{
    [$shade, $mix] = $variant;
    $n = count($pal);
    $c = $pal[$hue];
    if ($mix !== 0.0) {
        $c = lkMix($c, $pal[($hue + ($mix > 0 ? 1 : -1) + $n) % $n], abs($mix));
    }
    return lkShade($c, $shade);
}

// ================================================================ BUILD

/** @return array{mask: list<string>, region: array, lead: array, colour: array} */
function design(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    // Lay out each line, centre it on the widest, stack with ONE shared
    // came row between lines (the bottom outline of CANDY = top of TOP).
    $lines = array_map(static fn (string $t) => lkLayout(FONT, $t, letterGap: 1, wordGap: 3, margin: 1), LINES);
    $w = max(array_map(static fn ($l) => strlen($l[0][0]), $lines));
    $mask = [];
    $ranges = [];
    $blank = str_repeat('.', $w);
    foreach ($lines as [$m, $r]) {
        $off = intdiv($w - strlen($m[0]), 2);
        $mask[] = $blank;                         // came row above this line
        $y0 = count($mask);
        foreach ($m as $row) {
            $mask[] = str_pad(str_repeat('.', $off) . $row, $w, '.');
        }
        foreach ($r as [$x0, $x1]) {
            $ranges[] = [$x0 + $off, $x1 + $off, $y0, $y0 + count($m)];
        }
    }
    $mask[] = $blank;
    [$region, $lead] = sgVoronoi($mask, $ranges, PANE_SPACING, SEED);
    $colour = sgColourPanes($region, $lead, count(PANE_VARIANTS), SEED);
    $letterOf = [];
    foreach ($ranges as $li => [$x0, $x1, $y0, $y1]) {
        foreach ($region as $y => $row) {
            foreach ($row as $x => $id) {
                if ($x >= $x0 && $x < $x1 && $y >= $y0 && $y < $y1) {
                    $letterOf[$id] = $li;
                }
            }
        }
    }
    return $d = compact('mask', 'region', 'lead', 'colour', 'letterOf');
}

/**
 * Pixel grid. $glint = band position (px along x + 0.8y), null = static.
 * $depth '16' drops the texture; 256/16 push the spill into one dim-hue row.
 */
function pixels(string $depth, ?float $glint = null): array
{
    ['mask' => $mask, 'region' => $region, 'lead' => $lead, 'colour' => $colour, 'letterOf' => $letterOf] = design();
    $pal = array_map('lkHex', array_values(PALETTE));
    $h = count($mask);
    $w = strlen($mask[0]);
    $isGlass = static fn (int $x, int $y): bool => isset($region[$y][$x]) && empty($lead[$y][$x]);
    $isCame = static fn (int $x, int $y): bool => !$isGlass($x, $y) && (isset($region[$y][$x]) || (function () use ($x, $y, $region): bool {
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (isset($region[$y + $dy][$x + $dx])) {
                    return true;
                }
            }
        }
        return false;
    })());
    $band = static function (int $x, int $y) use ($glint): float {
        if ($glint === null) {
            return 0.0;
        }
        $d = abs($x + 0.8 * $y - $glint);
        return $d < 3.0 ? 1 - $d / 3.0 : 0.0;
    };

    $px = array_fill(0, $h + 1, array_fill(0, $w, null));
    $glassOnly = array_fill(0, $h, array_fill(0, $w, null));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($isGlass($x, $y)) {
                $id = $region[$y][$x];
                $c = sgPaneColour($pal, LETTER_HUES[$letterOf[$id]], PANE_VARIANTS[$colour[$id]]);
                // Sun from the upper left.
                $sun = 1 - (0.45 * $x / $w + 0.55 * $y / $h);
                $c = lkScale($c, 0.74 + 0.40 * $sun);
                // Per-pane bevel: lit where the came is above/left, shaded below/right.
                $litEdge = !$isGlass($x - 1, $y) || !$isGlass($x, $y - 1);
                $darkEdge = !$isGlass($x + 1, $y) || !$isGlass($x, $y + 1);
                if ($litEdge && !$darkEdge) {
                    $c = lkShade($c, 0.22);
                } elseif ($darkEdge && !$litEdge) {
                    $c = lkShade($c, -0.14);
                }
                $glassOnly[$y][$x] = $c;
                if ($depth !== '16') {
                    $c = lkScale($c, 1 + TEXTURE * (2 * lkNoise($x, $y, SEED + 99) - 1));
                }
                if (($b = $band($x, $y)) > 0) {
                    $c = lkMix($c, lkHex(GLINT), 0.6 * $b);
                }
                $px[$y][$x] = $c;
            } elseif ($isCame($x, $y)) {
                $c = lkHex(isset($region[$y][$x]) ? LEAD_LIT : LEAD);
                if ($band($x, $y) > 0.4) {
                    $c = lkHex(GLINT_LEAD);
                }
                $px[$y][$x] = $c;
            }
        }
    }
    // Light pool on the sill (two pixel rows under the window).
    // Stacked words: each column's light comes from the nearest glass above
    // the sill only (else TOP's spill turns muddy with CANDY's hues).
    for ($x = 0; $x < $w; $x++) {
        $low = null;
        for ($y = $h - 1; $y >= 0 && $low === null; $y--) {
            $low = $glassOnly[$y][$x] !== null ? $y : null;
        }
        for ($y = 0; $low !== null && $y < $low - 8; $y++) {
            $glassOnly[$y][$x] = null;
        }
    }
    $spill = sgSpill($glassOnly, SPILL_BLUR);
    foreach ($spill as $x => $c) {
        if ($c === null) {
            continue;
        }
        if ($depth !== 'tc') {
            // Palettes lack dark saturated tones: normalise into the dim-but-hued range.
            $m = max($c) ?: 1;
            $px[$h][$x] = lkScale($c, ($depth === '16' ? 125 : 118) / $m);
            continue;
        }
        $px[$h][$x] = lkScale($c, SPILL[0]);
    }
    return $px;
}

function cells(string $depth, ?float $glint = null): array
{
    return lkHalfBlock(pixels($depth, $glint));
}

// ================================================================ CLI

if (in_array('--regions', $argv, true)) {
    ['mask' => $mask, 'region' => $region, 'lead' => $lead] = design();
    foreach ($mask as $y => $row) {
        $s = '';
        for ($x = 0; $x < strlen($row); $x++) {
            $s .= isset($region[$y][$x]) ? (!empty($lead[$y][$x]) ? '+' : chr(65 + $region[$y][$x] % 58)) : '.';
        }
        echo $s, "\n";
    }
    exit;
}

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $static = lkEncode(cells($depth), $depth, PINS16);
    [$w, $h] = lkVerify($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    $width = count(cells($depth)[0]);
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $frames[] = lkEncode(cells($depth, -14 + ($width + 28) * $f / (ANIM_FRAMES - 1)), $depth, PINS16);
    }
    $anim = lkAnim($frames, $static);
    if ($write) {
        $label = ['tc' => '24-bit colour', '256' => 'xterm-256 downgrade', '16' => '16-colour downgrade (texture dropped, single-row spill)'][$depth];
        $r = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " $label.", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = skWriteSkinny(
            $dir,
            SLUG . '-anim',
            $depth,
            $anim,
            'Animated skinny stained-glass-candy (CANDY over TOP): a warm sun glint sweeps diagonally across the leaded candy-glass panes (the came flashes gold), ' . ANIM_FRAMES . ' frames, ~2.1 s with tools/logo-play.php at 100 ms; ends on the static logo with the cursor restored. ' . $label . '.',
            [...TAGS, 'animated', 'glint-sweep'],
        );
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
