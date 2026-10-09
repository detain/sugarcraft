<?php

declare(strict_types=1);

/**
 * skinny-type-fit — plan TTF lettering for a small (skinny) canvas BEFORE
 * writing a generator: fit one or more words of any TTF/OTF font into cell
 * boxes at sextant / quadrant / half-block resolution, preview the result in
 * the terminal (each line its own colour), and report how it fitted:
 * condense factor actually used, ink box in cells, and the thinnest
 * horizontal/vertical stroke in sub-pixels (thin strokes are what makes small
 * lettering illegible: aim for ≥ 2 sub-pixels).
 *
 * Built on ttf-coverage.php (tcRender) + sextant-image-raster.php; the
 * returned coverage grids are exactly what a generator gets from tcRender with
 * the same arguments, so copy the winning --line straight into your code.
 *
 * Usage:
 *   php skinny-type-fit.php --grid=40x11 [--raster=sextant|quadrant|half]
 *       --line='FONT|TEXT|x0,y0,x1,y1[|condense[|tracking[|embolden]]]' [--line=...]
 *       [--png=FILE.png] [--ascii]
 *   php skinny-type-fit.php --fonts [filter]     list display-weight fonts (fc-list)
 *   php skinny-type-fit.php --help
 *
 *   FONT      path, or a short name resolved via fc-list (e.g. URWGothic-Demi)
 *   TEXT      the word(s); spaces allowed
 *   x0..y1    target ink box in CELLS (fractions ok; x1/y1 exclusive), e.g.
 *             1,1,39,5 = cols 1-38, rows 1-4. Text is fitted to the box height,
 *             condensed horizontally down to `condense` (default 0.6) if too
 *             wide, then shrunk uniformly and centred.
 *   tracking  extra letter spacing in font units at size 200 (negative = tighter)
 *   embolden  grow the strokes by this many sub-pixels (≥ 1 to have any effect:
 *             1 = sideways only, 1.6 = also up/down at sextant aspect) — the
 *             cheapest fix for 1-sub-pixel hairlines at small sizes; generators
 *             can call stfEmbolden($r['cov'], $r) the same way
 *   --ascii   print the sub-pixel mask as ASCII instead of the ANSI preview
 *   --png     also write a PNG preview (uses skinny-raster-kit's exact renderer)
 *
 * Example (stack CANDY over TOP in a 40×11 frame):
 *   php skinny-type-fit.php --grid=40x11 \
 *     --line='URWGothic-Demi|CANDY|1,1,39,5|0.8|20' \
 *     --line='NimbusSansNarrow-Bold|TOP|14,5,33,10|0.6|10'
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/ttf-coverage.php';
require_once __DIR__ . '/sextant-image-raster.php';

const STF_COLOURS = ['#FF5FAF', '#5FD7FF', '#FFD75F', '#87FF87', '#D787FF', '#FF875F'];

function stfHelp(): void
{
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
}

/** Resolve a font path or fc-list short name (file basename without extension). */
function stfFont(string $f): string
{
    if (is_file($f)) {
        return $f;
    }
    exec('fc-list : file 2>/dev/null', $out);
    foreach ($out as $l) {
        $p = rtrim(trim($l), ':');
        if (strcasecmp(pathinfo($p, PATHINFO_FILENAME), $f) === 0 && preg_match('/\.(ttf|otf)$/i', $p)) {
            return $p;
        }
    }
    throw new InvalidArgumentException("font not found: $f (try --fonts)");
}

/**
 * Typical thinnest stroke: 10th-percentile length of covered sub-pixel runs
 * across rows (h) and down columns (v). A plain minimum is useless — every
 * apex, terminal and curve tip is a 1-pixel run.
 */
function stfStroke(array $cov): array
{
    $runs = static function (array $lines): int {
        $all = [];
        foreach ($lines as $line) {
            $run = 0;
            foreach ([...$line, 0.0] as $v) {
                if ($v >= 0.5) {
                    $run++;
                } elseif ($run > 0) {
                    $all[] = $run;
                    $run = 0;
                }
            }
        }
        if (!$all) {
            return 0;
        }
        sort($all);
        return $all[(int) floor(count($all) * 0.1)];
    };
    $cols = [];
    foreach ($cov as $y => $row) {
        foreach ($row as $x => $v) {
            $cols[$x][$y] = $v;
        }
    }
    return [$runs($cov), $runs($cols)];
}

/**
 * Embolden a coverage grid: max(coverage, dilated mask). Also grows the
 * 'letter' ownership into the new pixels (nearest owner in the 3×3
 * neighbourhood) so per-letter colouring still works.
 */
function stfEmbolden(array $cov, float $r, ?array &$letter = null, float $aspect = 1.5): array
{
    if ($r <= 0) {
        return $cov;
    }
    $dil = tcDilate($cov, $r, $aspect);
    foreach ($cov as $y => $row) {
        foreach ($row as $x => $v) {
            if ($dil[$y][$x] > $v) {
                $cov[$y][$x] = max($v, $dil[$y][$x]);
                if ($letter !== null && $letter[$y][$x] < 0) {
                    for ($d = 1; $d <= (int) ceil($r) + 1 && $letter[$y][$x] < 0; $d++) {
                        foreach ([[-$d, 0], [$d, 0], [0, -$d], [0, $d], [-$d, -$d], [$d, $d], [-$d, $d], [$d, -$d]] as [$dx, $dy]) {
                            if (($letter[$y + $dy][$x + $dx] ?? -1) >= 0) {
                                $letter[$y][$x] = $letter[$y + $dy][$x + $dx];
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    return $cov;
}

/** Run one fit; returns tcRender result + report fields. */
function stfFit(string $font, string $text, array $boxCells, int $subW, int $subH, int $pw, int $ph, float $condense, float $tracking, float $embolden = 0.0): array
{
    $box = [$boxCells[0] * $subW, $boxCells[1] * $subH, $boxCells[2] * $subW, $boxCells[3] * $subH];
    $r = tcRender(stfFont($font), $text, $subW, $subH, $pw, $ph, array_map(static fn ($v) => (int) round($v), $box), ['maxCondense' => $condense, 'tracking' => $tracking, 'ss' => 4]);
    $r['cov'] = stfEmbolden($r['cov'], $embolden, $r['letter']);
    [$sh, $sv] = stfStroke($r['cov']);
    $b = $r['box'];
    $r['report'] = sprintf('ink cells x %.1f-%.1f  y %.1f-%.1f  condense %.2f  thin strokes (p10) h=%d v=%d sub-px%s',
        $b[0] / $subW, $b[2] / $subW, $b[1] / $subH, $b[3] / $subH, $r['condense'], $sh, $sv,
        min($sh, $sv) < 2 ? '  ⚠ thin (legibility risk)' : '');
    return $r;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    if (!$args || in_array('--help', $args, true) || in_array('-h', $args, true)) {
        stfHelp();
        exit($args ? 0 : 2);
    }
    if ($args[0] === '--fonts') {
        $filter = $args[1] ?? '';
        exec('fc-list : family style file 2>/dev/null', $out);
        $seen = [];
        foreach ($out as $l) {
            if (!preg_match('/(Bold|Black|Heavy|Demi|Semi|Extra|Ultra|Condensed|Narrow)/i', $l) || ($filter !== '' && stripos($l, $filter) === false)) {
                continue;
            }
            $p = trim(explode(':', $l)[0]);
            if (preg_match('/\.(ttf|otf)$/i', $p) && !isset($seen[$p])) {
                $seen[$p] = true;
                printf("%-40s %s\n", pathinfo($p, PATHINFO_FILENAME), $l);
            }
        }
        exit(0);
    }
    $grid = [40, 11];
    $raster = 'sextant';
    $lines = [];
    $png = null;
    $ascii = false;
    foreach ($args as $a) {
        if (preg_match('/^--grid=(\d+)x(\d+)$/', $a, $m)) {
            $grid = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^--raster=(\w+)$/', $a, $m)) {
            $raster = $m[1];
        } elseif (str_starts_with($a, '--line=')) {
            $lines[] = explode('|', substr($a, 7));
        } elseif (str_starts_with($a, '--png=')) {
            $png = substr($a, 6);
        } elseif ($a === '--ascii') {
            $ascii = true;
        }
    }
    [$subW, $subH] = match ($raster) {
        'sextant' => [2, 3], 'quadrant' => [2, 2], 'half' => [1, 2],
        default => throw new InvalidArgumentException("raster $raster"),
    };
    $pw = $grid[0] * $subW;
    $ph = $grid[1] * $subH;
    $img = array_fill(0, $ph, array_fill(0, $pw, [0, 0, 0]));
    $maskChars = array_fill(0, $ph, str_repeat('.', $pw));
    foreach ($lines as $i => $l) {
        [$font, $text, $box] = $l;
        $r = stfFit($font, $text, array_map('floatval', explode(',', $box)), $subW, $subH, $pw, $ph, (float) ($l[3] ?? 0.6), (float) ($l[4] ?? 0.0), (float) ($l[5] ?? 0.0));
        fwrite(STDERR, sprintf("line %d %-12s %s\n", $i + 1, "\"$text\"", $r['report']));
        $col = lkHex(STF_COLOURS[$i % count(STF_COLOURS)]);
        foreach ($r['cov'] as $y => $row) {
            foreach ($row as $x => $v) {
                if ($v > 0.02) {
                    $img[$y][$x] = lkMix($img[$y][$x], $col, min(1.0, $v));
                }
                if ($v >= 0.5) {
                    $maskChars[$y][$x] = (string) ($i + 1);
                }
            }
        }
    }
    if ($ascii) {
        echo implode("\n", $maskChars), "\n";
    } else {
        $cells = $raster === 'sextant' ? siCells($img) : (function () use ($img, $raster) {
            require_once __DIR__ . '/skinny-raster-kit.php';
            return skRaster($img, $raster, 'tc');
        })();
        echo lkEncode($cells, 'tc');
        if ($png !== null) {
            require_once __DIR__ . '/skinny-raster-kit.php';
            skPng($cells, $png);
            fwrite(STDERR, "png → $png\n");
        }
    }
}
