<?php

declare(strict_types=1);

/**
 * skinny-depth — hue-preserving, ROLE-AWARE tc → 256 → 16 colour encoding
 * for cell grids, plus a CLI that downgrades any truecolour .ansi file.
 *
 * Why: lkEncode's nearest-colour 256 mapping turns dark tinted glows /
 * screens / gradients into blotchy greys and odd blues, and plain 16-colour
 * mapping turns mid-dark tints into coloured blocks. Skinny logos are small,
 * so every blotch shows. This kit:
 *   - sdTo256Hue(): saturated colours only match the 6×6×6 cube's chromatic
 *     entries (never the grey ramp, never the cube's own greys);
 *   - sdTo16Hue(): dark → black, near-white → bright white, otherwise a hue
 *     band (red/orange/yellow/green/cyan/blue/violet/magenta), bright vs normal
 *     by value — so pastels keep their hue;
 *   - ROLES: a cell may carry a role name at index 3 ([glyph, fg, bg, role,
 *     …extra]). A role table pins per-depth fg/bg codes (or callables) for
 *     roles such as 'screen', 'glow', 'grid', 'shadow', so backgrounds can
 *     collapse to clean flat colours in 256/16 while the artwork keeps hue.
 *
 * ---------------------------------------------------------------- library
 *   require __DIR__ . '/skinny-depth.php';
 *   sdTo256Hue(array $rgb, bool $forceHue = false): int        xterm index
 *   sdTo16Hue(array $rgb, array $o = []): int                  30-37 / 90-97
 *       $o: darkMax (125) — max channel below this → black (30)
 *           whiteMin (185) — min channel above this (or chroma < greyChroma) → 97
 *           greyChroma (45), brightMin (170) — max channel ≥ this → bright code
 *   sdEncode(array $cells, string $depth, array $roles = [], array $o16 = []): string
 *       $roles = ['screen' => ['256' => ['bg' => 233], '16' => ['bg' => 40, 'fg' => 90]],
 *                 'glow'   => ['256' => ['bg' => fn(array $rgb, array $cell): string => '48;5;' . …]]]
 *       a value is an int (xterm index for 256 / SGR code for 16) or a callable
 *       returning the full SGR parameter string. Unlisted roles / fg / bg fall
 *       back to sdTo256Hue / sdTo16Hue. tc always encodes the exact rgb.
 *       Every line ends with \e[0m (lkVerify-compatible).
 *   sdDowngradeAnsi(string $tcAnsi, string $depth, array $o16 = []): string
 *       rewrite 38;2/48;2 codes in finished tc text (no roles available).
 *
 * ---------------------------------------------------------------- CLI
 *   php skinny-depth.php <in-tc.ansi> --depth=256|16 [--dark=125] [--white=185] > out.ansi
 *   Downgrades a truecolour file (animations too: every frame) with the
 *   hue-preserving mappers. Example:
 *     php skinny-depth.php ../logo-foo-skinny-tc-40x9.ansi --depth=16 > /tmp/x16.ansi
 */

require_once __DIR__ . '/logo-kit.php';

function sdTo256Hue(array $c, bool $forceHue = false): int
{
    static $memo = [];
    $k = implode(',', $c) . ($forceHue ? 'h' : '');
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $max = max($c);
    $sat = $max > 0 ? ($max - min($c)) / $max : 0;
    if ($sat < 0.25 && !$forceHue) {
        return $memo[$k] = lkTo256($c);
    }
    $pal = lkXterm256();
    [$best, $bd] = [16, INF];
    for ($i = 16; $i < 232; $i++) {
        $q = $i - 16;
        if (intdiv($q, 36) === intdiv($q % 36, 6) && intdiv($q % 36, 6) === $q % 6) {
            continue;                                   // cube greys
        }
        $d = lkDist($c, $pal[$i]);
        if ($d < $bd) {
            [$bd, $best] = [$d, $i];
        }
    }
    return $memo[$k] = $best;
}

function sdTo16Hue(array $c, array $o = []): int
{
    $o += ['darkMax' => 125, 'whiteMin' => 185, 'greyChroma' => 45, 'brightMin' => 170];
    [$r, $g, $b] = $c;
    $max = max($c);
    $min = min($c);
    if ($max < $o['darkMax']) {
        return 30;
    }
    if ($min > $o['whiteMin'] || ($max - $min) < $o['greyChroma']) {
        return $max >= 200 ? 97 : ($max >= 140 ? 37 : 90);
    }
    $h = rad2deg(atan2(sqrt(3) * ($g - $b), 2 * $r - $g - $b));
    $h = $h < 0 ? $h + 360 : $h;
    $base = match (true) {
        $h >= 345 || $h < 15 => 31,
        $h < 42 => 33,                                  // orange → (dark) yellow
        $h < 75 => 33,
        $h < 160 => 32,
        $h < 205 => 36,
        $h < 245 => 34,
        $h < 285 => 35,                                 // violet stays dim magenta
        default => 35,
    };
    $orange = $h >= 15 && $h < 42;
    $violet = $h >= 245 && $h < 285;
    return ($max >= $o['brightMin'] && !$orange && !$violet) ? $base + 60 : $base;
}

/** One cell colour → SGR parameter string. */
function sdSgr(?array $c, bool $bg, string $depth, array $cell, array $roles, array $o16): string
{
    if ($c === null) {
        return $bg ? '49' : '39';
    }
    if ($depth === 'tc') {
        return ($bg ? '48' : '38') . ";2;{$c[0]};{$c[1]};{$c[2]}";
    }
    $role = is_string($cell[3] ?? null) ? $cell[3] : null;
    $pin = $role !== null ? ($roles[$role][$depth][$bg ? 'bg' : 'fg'] ?? null) : null;
    if (is_callable($pin)) {
        return $pin($c, $cell);
    }
    if ($depth === '256') {
        return ($bg ? '48' : '38') . ';5;' . ($pin ?? sdTo256Hue($c));
    }
    if ($pin !== null) {
        return (string) $pin;
    }
    return (string) (sdTo16Hue($c, $o16) + ($bg ? 10 : 0));
}

function sdEncode(array $cells, string $depth, array $roles = [], array $o16 = []): string
{
    $out = '';
    foreach ($cells as $row) {
        [$curFg, $curBg, $line] = ['39', '49', ''];
        foreach ($row as $cell) {
            [$ch, $fg, $bg] = $cell;
            if ($ch === '') {
                continue;                               // wide-glyph placeholder
            }
            $codes = [];
            $b = sdSgr($bg, true, $depth, $cell, $roles, $o16);
            if ($b !== $curBg) {
                $codes[] = $curBg = $b;
            }
            if ($ch !== ' ') {
                $f = sdSgr($fg, false, $depth, $cell, $roles, $o16);
                if ($f !== $curFg) {
                    $codes[] = $curFg = $f;
                }
            }
            $line .= ($codes ? "\e[" . implode(';', $codes) . 'm' : '') . $ch;
        }
        $out .= $line . "\e[0m\n";
    }
    return $out;
}

function sdDowngradeAnsi(string $ansi, string $depth, array $o16 = []): string
{
    if ($depth === 'tc') {
        return $ansi;
    }
    return preg_replace_callback('/([34])8;2;(\d+);(\d+);(\d+)/', static function (array $m) use ($depth, $o16): string {
        $c = [(int) $m[2], (int) $m[3], (int) $m[4]];
        $bg = $m[1] === '4';
        return $depth === '256' ? ($bg ? '48' : '38') . ';5;' . sdTo256Hue($c) : (string) (sdTo16Hue($c, $o16) + ($bg ? 10 : 0));
    }, $ansi);
}

// ================================================================ CLI

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $in = null;
    $o = ['depth' => '256'];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
            echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
            exit(0);
        }
        if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
            $o[$m[1]] = $m[2];
        } else {
            $in = $arg;
        }
    }
    if ($in === null || !is_file($in)) {
        fwrite(STDERR, "usage: php skinny-depth.php <in-tc.ansi> --depth=256|16 [--dark=125] [--white=185]  (--help)\n");
        exit(1);
    }
    $o16 = array_filter(['darkMax' => isset($o['dark']) ? (int) $o['dark'] : null, 'whiteMin' => isset($o['white']) ? (int) $o['white'] : null], static fn ($v) => $v !== null);
    $out = sdDowngradeAnsi(file_get_contents($in), $o['depth'], $o16);
    lkCheckDepth($out, $o['depth'], basename($in));
    echo $out;
}
