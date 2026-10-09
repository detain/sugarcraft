<?php

declare(strict_types=1);

/**
 * skinny-palette — hue-faithful tc → 256 / 16 colour mapping (library + CLI).
 *
 * Why: logo-kit's plain nearest-colour mapping turns golds olive/pink at 256
 * and collapses neighbouring letter colours (pink+magenta, orange+yellow) into
 * the same 16-colour code, while faint background texture turns into grey
 * checkerboards. These mappings fix that and are generic (no logo specifics):
 *
 *   spHueSat(rgb)                       → [hue°, saturation]
 *   spTo256(rgb, opts)                  → xterm-256 palette rgb. Bright saturated
 *                                         colours only match cube entries within
 *                                         maxDh° of their own hue; dark/grey ones
 *                                         use plain nearest.
 *   spCode16(rgb, opts)                 → 16-colour fg code (30-37/90-97) by HUE
 *                                         BAND table, with black/white/grey rules
 *   spRgb16(code)                       → rgb of a 16-colour code
 *   spQuantise(img, depth, opts)        → sub-pixel image (rows of rgb|null)
 *                                         snapped to palette rgb — feed to
 *                                         siCells($img, true) / skRaster so each
 *                                         cell's two colours are real entries
 *   spCells(cells, depth, opts)         → [cells, pins16] for a finished cell grid
 *                                         (then lkEncode($cells, $depth, $pins16))
 *   spEncode(cells, depth, opts)        → ANSI string in one call
 *
 * opts (all optional):
 *   'maxDh' => 15          256: hue tolerance in degrees
 *   'minSat' => 0.2        256: saturation above which the hue lock applies
 *   'minMax' => 150        256: brightness (0-255 max channel) above which it applies
 *   'bands' => [[34,31],[75,33],[165,32],[205,36],[255,34],[300,35],[345,35],[360,31]]
 *                          16: [hue upper limit, base code] in ascending order;
 *                          the default splits orange→red and gold→yellow so
 *                          rainbow letter rows keep distinct neighbours
 *   'black' => 0.28        16: max channel (0-1) below which → 30 (black)
 *   'blackHue' => null     16: [h0, h1, maxV] extra "treat as background" rule,
 *                          e.g. [240, 290, 0.6] sends dark violets to black
 *   'pastel' => 0.28       16: saturation below which → 97/37/90 by brightness
 *   'bright' => 0.62       16: max channel above which the band code gets +60
 *   'warmDark' => [15, 50, 0.8]  16: hues h0..h1 with max < v → 33 (gold rims
 *                          and bronze never turn red); null to disable
 *   'pins' => ['#RRGGBB' => code, ...]  16: exact overrides, checked first
 *
 * CLI (re-derive 256/16 files from a finished tc .ansi, e.g. a skinny logo):
 *   php skinny-palette.php <logo-…-tc-WxH.ansi> [--depth=256,16] [--out=DIR]
 *       [--bands=34:31,75:33,…] [--black=0.28] [--black-hue=240:290:0.6]
 *       [--no-warm-dark] [--max-dh=15] [--pin=#RRGGBB:93 …] [--stdout]
 *     writes logo-…-256-WxH.ansi / logo-…-16-WxH.ansi next to the input (or in
 *     --out), last frame only for animations. No logos.jsonl writes — add those
 *     with your generator / skWriteSkinny.
 *   php skinny-palette.php --probe=#FF8C1A,#E0A030,… [band/threshold flags]
 *     prints the 256 and 16 mapping of each colour (tune bands quickly).
 *   php skinny-palette.php --help
 *
 * Mapping a SUB-PIXEL image before the 2-colour cell fit (spQuantise) is
 * cleaner than mapping finished cells (spCells): use spQuantise inside a
 * generator, the CLI for already-rendered files.
 */

require_once __DIR__ . '/logo-kit.php';

const SP_BANDS = [[34, 31], [75, 33], [165, 32], [205, 36], [255, 34], [300, 35], [345, 35], [360, 31]];

/** @return array{0: float, 1: float} [hue°, saturation] */
function spHueSat(array $c): array
{
    [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $d = $max - $min;
    if ($d == 0.0) {
        return [0.0, 0.0];
    }
    $h = match (true) {
        $max == $r => 60 * fmod(($g - $b) / $d, 6),
        $max == $g => 60 * (($b - $r) / $d + 2),
        default => 60 * (($r - $g) / $d + 4),
    };
    return [$h < 0 ? $h + 360 : $h, $d / $max];
}

/** Hue-locked nearest xterm-256 → palette rgb. */
function spTo256(array $c, array $o = []): array
{
    static $memo = [];
    $maxDh = (float) ($o['maxDh'] ?? 15);
    $minSat = (float) ($o['minSat'] ?? 0.2);
    $minMax = (int) ($o['minMax'] ?? 150);
    $key = implode(',', $c) . "/$maxDh/$minSat/$minMax";
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    [$h, $s] = spHueSat($c);
    $lock = $s > $minSat && max($c) > $minMax;
    $best = null;
    $bd = INF;
    foreach (lkXterm256() as $i => $p) {
        if ($i < 16) {
            continue;
        }
        if ($lock) {
            [$ph, $ps] = spHueSat($p);
            $dh = abs($ph - $h);
            if ($ps < 0.15 || min($dh, 360 - $dh) > $maxDh) {
                continue;
            }
        }
        $d = lkDist($c, $p);
        if ($d < $bd) {
            $bd = $d;
            $best = $p;
        }
    }
    return $memo[$key] = $best ?? lkXterm256()[lkTo256($c)];
}

/** 16-colour fg code by hue band. */
function spCode16(array $c, array $o = []): int
{
    $hex = sprintf('#%02X%02X%02X', ...$c);
    foreach ($o['pins'] ?? [] as $k => $code) {
        if (strtoupper((string) $k) === $hex) {
            return (int) $code;
        }
    }
    [$h, $s] = spHueSat($c);
    $max = max($c) / 255;
    if ($max < (float) ($o['black'] ?? 0.28)) {
        return 30;
    }
    if (($bh = $o['blackHue'] ?? null) !== null && $h >= $bh[0] && $h <= $bh[1] && $max < $bh[2]) {
        return 30;
    }
    if ($s < (float) ($o['pastel'] ?? 0.28)) {
        return $max > 0.75 ? 97 : ($max > 0.5 ? 37 : 90);
    }
    $wd = array_key_exists('warmDark', $o) ? $o['warmDark'] : [15, 50, 0.8];
    if ($wd !== null && $h >= $wd[0] && $h < $wd[1] && $max < $wd[2]) {
        return 33;
    }
    $bright = $max > (float) ($o['bright'] ?? 0.62);
    foreach ($o['bands'] ?? SP_BANDS as [$lim, $code]) {
        if ($h < $lim) {
            return $bright ? $code + 60 : $code;
        }
    }
    return $bright ? 95 : 35;
}

function spRgb16(int $code): array
{
    return lkAnsi16()[$code >= 40 && $code < 50 || $code >= 100 ? $code - 10 : $code];
}

/** Snap every sub-pixel of an image to the depth's palette (tc passes through). */
function spQuantise(array $img, string $depth, array $o = []): array
{
    if ($depth === 'tc') {
        return $img;
    }
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c !== null) {
                $img[$y][$x] = $depth === '256' ? spTo256($c, $o) : spRgb16(spCode16($c, $o));
            }
        }
    }
    return $img;
}

/**
 * Map a finished cell grid: 256 → colours replaced by palette rgb; 16 → a pins
 * table (rgb key → code) so lkEncode emits exactly the chosen codes.
 * @return array{0: array, 1: array<string,int>}
 */
function spCells(array $cells, string $depth, array $o = []): array
{
    $pins = [];
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $cell) {
            foreach ([1, 2] as $k) {
                $c = $cell[$k] ?? null;
                if ($c === null) {
                    continue;
                }
                if ($depth === '256') {
                    $cells[$y][$x][$k] = spTo256($c, $o);
                } elseif ($depth === '16') {
                    $pins[implode(',', $c)] = spCode16($c, $o);
                }
            }
        }
    }
    return [$cells, $pins];
}

function spEncode(array $cells, string $depth, array $o = []): string
{
    [$cells, $pins] = spCells($cells, $depth, $o);
    return lkEncode($cells, $depth, $pins, 'nearest');
}

/** CLI flags → opts. */
function spOptsFromArgv(array $argv): array
{
    $o = [];
    foreach ($argv as $a) {
        if (preg_match('/^--bands=(.+)$/', $a, $m)) {
            $o['bands'] = array_map(static fn (string $p): array => array_map('intval', explode(':', $p)), explode(',', $m[1]));
        } elseif (preg_match('/^--black=([\d.]+)$/', $a, $m)) {
            $o['black'] = (float) $m[1];
        } elseif (preg_match('/^--black-hue=([\d.]+):([\d.]+):([\d.]+)$/', $a, $m)) {
            $o['blackHue'] = [(float) $m[1], (float) $m[2], (float) $m[3]];
        } elseif ($a === '--no-warm-dark') {
            $o['warmDark'] = null;
        } elseif (preg_match('/^--max-dh=([\d.]+)$/', $a, $m)) {
            $o['maxDh'] = (float) $m[1];
        } elseif (preg_match('/^--pin=(#[0-9A-Fa-f]{6}):(\d+)$/', $a, $m)) {
            $o['pins'][$m[1]] = (int) $m[2];
        }
    }
    return $o;
}

// ================================================================ CLI

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    if (!$args || in_array('--help', $args, true) || in_array('-h', $args, true)) {
        preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
        echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
        exit($args ? 0 : 2);
    }
    $o = spOptsFromArgv($args);
    foreach ($args as $a) {
        if (str_starts_with($a, '--probe=')) {
            foreach (explode(',', substr($a, 8)) as $hex) {
                $c = lkHex(trim($hex));
                [$h, $s] = spHueSat($c);
                $p = spTo256($c, $o);
                $code = spCode16($c, $o);
                printf("%s  hue %5.1f sat %.2f  → 256 \e[48;5;%dm    \e[0m #%02X%02X%02X  → 16 \e[%dm████\e[0m %d\n",
                    $hex, $h, $s, lkTo256($p), ...[...$p, $code, $code]);
            }
            exit(0);
        }
    }
    require_once __DIR__ . '/skinny-raster-kit.php';   // skParse
    $in = null;
    $depths = ['256', '16'];
    $outDir = null;
    $stdout = in_array('--stdout', $args, true);
    foreach ($args as $a) {
        if (preg_match('/^--depth=(.+)$/', $a, $m)) {
            $depths = explode(',', $m[1]);
        } elseif (preg_match('/^--out=(.+)$/', $a, $m)) {
            $outDir = $m[1];
        } elseif ($a[0] !== '-') {
            $in = $a;
        }
    }
    if ($in === null || !is_file($in)) {
        fwrite(STDERR, "need an input tc .ansi file (see --help)\n");
        exit(2);
    }
    $cells = skParse(file_get_contents($in));
    foreach ($depths as $d) {
        $ansi = spEncode($cells, $d, $o);
        [$w, $h] = lkVerify($ansi, $d);
        lkCheckDepth($ansi, $d, $d);
        if ($stdout) {
            echo $ansi;
            continue;
        }
        $name = preg_replace('/-tc-\d+x\d+\.ansi$/', "-$d-{$w}x{$h}.ansi", basename($in));
        if ($name === basename($in)) {
            $name = preg_replace('/\.ansi$/', '', basename($in)) . "-$d-{$w}x{$h}.ansi";
        }
        $path = rtrim($outDir ?? dirname($in), '/') . "/$name";
        file_put_contents($path, $ansi);
        fwrite(STDERR, "wrote $path ({$w}x{$h})\n");
    }
}
