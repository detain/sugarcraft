<?php

declare(strict_types=1);

/**
 * logo-kit — shared, design-agnostic toolkit for candy-top ANSI logos.
 *
 * Merged from the first two campaign libs (rock-candy-prism-lib.php +
 * licorice-allsorts-lib.php); both originals are kept untouched because their
 * generators still require them. New generators should require THIS file.
 *
 * Pipeline
 *   design data → pixel grid (rows of rgb|null) ──lkHalfBlock()──┐
 *                 or hand-built cell grid (lkBlankCells/lkPut) ──┴→ cell grid
 *   cell grid  ──lkEncode($cells, 'tc'|'256'|'16', $pins16)──→ ANSI text
 *   ANSI text  ──lkVerify()──→ [w, h] (equal visible widths, \e[0m per line)
 *   lkAnim()   — frames → cursor-up animation ending on the static frame
 *   lkWriteLogo() — verify + depth-purity check + write file + jsonl line
 *
 * A cell is [glyph, fg rgb|null, bg rgb|null]; null bg = terminal default
 * (transparent), emitted as `49`. Colours are [r,g,b] int arrays.
 * Glyphs must be single-column, or a wide (2-col) glyph followed by a ''
 * placeholder cell — lkEncode skips '' cells.
 */

// ================================================================ colour math

/** '#RRGGBB' → [r,g,b] */
function lkHex(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Linear blend a→b by t∈[0,1]. */
function lkMix(array $a, array $b, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    return [
        (int) round($a[0] + ($b[0] - $a[0]) * $t),
        (int) round($a[1] + ($b[1] - $a[1]) * $t),
        (int) round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

/** Multiply brightness by k (k<1 darkens). */
function lkScale(array $c, float $k): array
{
    return array_map(static fn (int $v): int => (int) max(0, min(255, round($v * $k))), $c);
}

/** f>0 tints toward white by f, f<0 shades toward black by |f|. */
function lkShade(array $c, float $f): array
{
    return array_map(
        static fn (int $v): int => max(0, min(255, (int) round($f >= 0 ? $v + (255 - $v) * $f : $v * (1 + $f)))),
        $c,
    );
}

/**
 * Sample a multi-stop gradient at t∈[0,1].
 * @param list<array|string> $stops rgb arrays or hex strings, evenly spaced
 */
function lkGradient(array $stops, float $t): array
{
    $stops = array_map(static fn ($s) => is_string($s) ? lkHex($s) : $s, $stops);
    $n = count($stops) - 1;
    if ($n <= 0) {
        return $stops[0];
    }
    $t = max(0.0, min(1.0, $t)) * $n;
    $i = min($n - 1, (int) floor($t));
    return lkMix($stops[$i], $stops[$i + 1], $t - $i);
}

/** Deterministic hash noise in [0,1) for (x, y, seed) — reproducible textures. */
function lkNoise(int $x, int $y, int $seed = 0): float
{
    $h = ($x * 374761393 + $y * 668265263 + $seed * 2147483647) & 0xFFFFFFFF;
    $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;
    return (($h ^ ($h >> 16)) & 0xFFFFFF) / 0x1000000;
}

/** Perceptual-ish "redmean" squared distance. */
function lkDist(array $a, array $b): float
{
    $rm = ($a[0] + $b[0]) / 2;
    $dr = $a[0] - $b[0];
    $dg = $a[1] - $b[1];
    $db = $a[2] - $b[2];
    return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
}

// ================================================================ grids

/**
 * Pixel grid (rows of rgb|null) → cell grid using half blocks:
 * top pixel = fg of ▀, bottom = bg; one-sided cells use ▀/▄ on default bg.
 * Odd heights are padded with a transparent row.
 */
function lkHalfBlock(array $px): array
{
    $h = count($px);
    $w = count($px[0]);
    $cells = [];
    for ($y = 0; $y < $h; $y += 2) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $t = $px[$y][$x] ?? null;
            $b = $px[$y + 1][$x] ?? null;
            $row[] = match (true) {
                $t === null && $b === null => [' ', null, null],
                $b === null => ['▀', $t, null],
                $t === null => ['▄', $b, null],
                $t === $b => ['█', $t, null],
                default => ['▀', $t, $b],
            };
        }
        $cells[] = $row;
    }
    return $cells;
}

/** A w×h cell grid of spaces on the given bg (null = transparent). */
function lkBlankCells(int $w, int $h, ?array $bg = null): array
{
    return array_fill(0, $h, array_fill(0, $w, [' ', null, $bg]));
}

/**
 * Overlay a glyph at (x, y). fg/bg null keep the cell's existing colour
 * (so a glyph can sit on whatever bg is underneath). Out-of-range is ignored.
 */
function lkPut(array &$cells, int $x, int $y, string $glyph, ?array $fg = null, ?array $bg = null): void
{
    if (!isset($cells[$y][$x])) {
        return;
    }
    $old = $cells[$y][$x];
    $cells[$y][$x] = [$glyph, $fg ?? $old[1], $bg ?? $old[2]];
}

/** Write a string left→right starting at (x, y) via lkPut. */
function lkText(array &$cells, int $x, int $y, string $text, ?array $fg = null, ?array $bg = null): void
{
    foreach (mb_str_split($text) as $i => $g) {
        lkPut($cells, $x + $i, $y, $g, $fg, $bg);
    }
}

/** Sparse text row of width w: col => [glyph, rgb]. */
function lkTextRow(int $w, array $glyphs): array
{
    $row = array_fill(0, $w, [' ', null, null]);
    foreach ($glyphs as $x => [$g, $c]) {
        if ($x >= 0 && $x < $w) {
            $row[$x] = [$g, $c, null];
        }
    }
    return $row;
}

/**
 * Lay out "CANDY TOP" (or any text) from a glyph-mask font.
 * @param array<string, list<string>> $font char => rows of '#'/'.' (or any
 *        single-byte codes; '.' and ' ' = empty)
 * @return array{0: list<string>, 1: array<int, array{int,int}>} [mask rows,
 *         letter index => [x0, x1) column ranges]
 */
function lkLayout(array $font, string $text, int $letterGap = 1, int $wordGap = 4, int $margin = 0): array
{
    $h = count(reset($font));
    $mask = array_fill(0, $h, '');
    $ranges = [];
    $chars = str_split($text);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            foreach ($mask as $y => $_) {
                $mask[$y] .= str_repeat('.', $wordGap);
            }
            continue;
        }
        $g = $font[$ch] ?? throw new InvalidArgumentException("no glyph for '$ch'");
        $x0 = strlen($mask[0]) + $margin;
        $ranges[] = [$x0, $x0 + strlen($g[0])];
        foreach ($mask as $y => $_) {
            $mask[$y] .= $g[$y];
        }
        $next = $chars[$i + 1] ?? null;
        if ($next !== null && $next !== ' ') {
            foreach ($mask as $y => $_) {
                $mask[$y] .= str_repeat('.', $letterGap);
            }
        }
    }
    $pad = str_repeat('.', $margin);
    return [array_map(static fn (string $r): string => $pad . $r . $pad, $mask), $ranges];
}

// ================================================================ depth

/** xterm palette indices 16..255 → rgb (0..15 excluded: terminal-themed). */
function lkXterm256(): array
{
    static $pal = null;
    if ($pal !== null) {
        return $pal;
    }
    $pal = [];
    $s = [0, 95, 135, 175, 215, 255];
    for ($i = 0; $i < 216; $i++) {
        $pal[16 + $i] = [$s[intdiv($i, 36)], $s[intdiv($i, 6) % 6], $s[$i % 6]];
    }
    for ($i = 0; $i < 24; $i++) {
        $v = 8 + $i * 10;
        $pal[232 + $i] = [$v, $v, $v];
    }
    return $pal;
}

/** The 16 ANSI colours (xterm defaults) keyed by fg SGR code. */
function lkAnsi16(): array
{
    return [
        30 => [0, 0, 0], 31 => [205, 0, 0], 32 => [0, 205, 0], 33 => [205, 205, 0],
        34 => [0, 0, 238], 35 => [205, 0, 205], 36 => [0, 205, 205], 37 => [229, 229, 229],
        90 => [127, 127, 127], 91 => [255, 0, 0], 92 => [0, 255, 0], 93 => [255, 255, 0],
        94 => [92, 92, 255], 95 => [255, 0, 255], 96 => [0, 255, 255], 97 => [255, 255, 255],
    ];
}

function lkTo256(array $c): int
{
    static $memo = [];
    $k = implode(',', $c);
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $best = 16;
    $bd = INF;
    foreach (lkXterm256() as $n => $p) {
        $d = lkDist($c, $p);
        if ($d < $bd) {
            $bd = $d;
            $best = $n;
        }
    }
    return $memo[$k] = $best;
}

/**
 * 16-colour fg code (30-37/90-97) for an rgb.
 *
 * $pins (optional) overrides per colour, checked first:
 *   'r,g,b' or '#RRGGBB' => fg code  — exact-colour pins (designer-tuned)
 * Mode 'hue' (default): hue-preserving bucket — pastels keep their hue
 * instead of collapsing to white; 'nearest': plain redmean nearest.
 */
function lkTo16(array $c, array $pins = [], string $mode = 'hue'): int
{
    $key = implode(',', $c);
    $hex = sprintf('#%02X%02X%02X', ...$c);
    if (isset($pins[$key])) {
        return $pins[$key];
    }
    if (isset($pins[$hex])) {
        return $pins[$hex];
    }
    if ($mode === 'nearest') {
        $best = 30;
        $bd = INF;
        foreach (lkAnsi16() as $code => $p) {
            $d = lkDist($c, $p);
            if ($d < $bd) {
                $bd = $d;
                $best = $code;
            }
        }
        return $best;
    }
    [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $s = $max == 0.0 ? 0.0 : ($max - $min) / $max;
    if ($max < 0.30) {
        return $max < 0.12 ? 30 : 90;
    }
    if ($s < 0.25) {
        return $max > 0.75 ? 97 : ($max > 0.5 ? 37 : 90);
    }
    $d = $max - $min;
    $h = match (true) {
        $max == $r => 60 * fmod(($g - $b) / $d, 6),
        $max == $g => 60 * (($b - $r) / $d + 2),
        default => 60 * (($r - $g) / $d + 4),
    };
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
    return $max > 0.62 ? $base + 60 : $base;
}

/** SGR param for fg/bg colour at a depth. null bg → 49, null fg → 39. */
function lkSgr(?array $c, bool $bg, string $depth, array $pins16 = [], string $mode16 = 'hue'): string
{
    if ($c === null) {
        return $bg ? '49' : '39';
    }
    return match ($depth) {
        'tc' => ($bg ? '48' : '38') . ";2;{$c[0]};{$c[1]};{$c[2]}",
        '256' => ($bg ? '48' : '38') . ';5;' . lkTo256($c),
        '16' => (string) (lkTo16($c, $pins16, $mode16) + ($bg ? 10 : 0)),
        default => throw new InvalidArgumentException("depth $depth"),
    };
}

/**
 * Encode a cell grid at depth tc|256|16. SGR only on state change; every
 * line ends with \e[0m. '' cells (wide-glyph placeholders) are skipped.
 */
function lkEncode(array $cells, string $depth, array $pins16 = [], string $mode16 = 'hue'): string
{
    $out = '';
    foreach ($cells as $row) {
        $curFg = '39';
        $curBg = '49';
        $line = '';
        foreach ($row as [$ch, $fg, $bg]) {
            if ($ch === '') {
                continue;
            }
            $codes = [];
            $b = lkSgr($bg, true, $depth, $pins16, $mode16);
            if ($b !== $curBg) {
                $codes[] = $curBg = $b;
            }
            if ($ch !== ' ') {
                $f = lkSgr($fg, false, $depth, $pins16, $mode16);
                if ($f !== $curFg) {
                    $codes[] = $curFg = $f;
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

/**
 * Rewrite every 38;2/48;2 in an already-built truecolor ANSI string to 256
 * or 16 colours (for generators that emit tc text directly).
 */
function lkDowngradeAnsi(string $ansi, string $depth, array $pins16 = [], string $mode16 = 'hue'): string
{
    if ($depth === 'tc') {
        return $ansi;
    }
    return preg_replace_callback(
        '/([34])8;2;(\d+);(\d+);(\d+)/',
        static fn (array $m): string => lkSgr([(int) $m[2], (int) $m[3], (int) $m[4]], $m[1] === '4', $depth, $pins16, $mode16),
        $ansi,
    );
}

// ================================================================ verify

function lkStrip(string $s): string
{
    return preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $s);
}

/**
 * Every line has the same visible width and ends with \e[0m.
 * @return array{0:int,1:int} [width, height]
 */
function lkVerify(string $ansi, string $label = 'logo'): array
{
    $lines = explode("\n", rtrim($ansi, "\n"));
    $widths = [];
    foreach ($lines as $i => $l) {
        if (!str_ends_with(str_replace("\e[?25h", '', $l), "\e[0m")) {
            throw new RuntimeException("$label: line $i does not end with \\e[0m");
        }
        $widths[] = mb_strwidth(lkStrip($l), 'UTF-8');
    }
    if (count(array_unique($widths)) !== 1) {
        throw new RuntimeException("$label: ragged widths " . implode(',', $widths));
    }
    return [$widths[0], count($lines)];
}

/** Throw if an ANSI string uses colour codes outside its depth. */
function lkCheckDepth(string $ansi, string $depth, string $label = 'logo'): void
{
    preg_match_all('/\e\[([0-9;]*)m/', $ansi, $m);
    foreach ($m[1] as $seq) {
        $p = $seq === '' ? [0] : array_map('intval', explode(';', $seq));
        for ($i = 0; $i < count($p); $i++) {
            $v = $p[$i];
            if ($v === 38 || $v === 48) {
                $kind = $p[$i + 1] ?? -1;
                if (($depth === '16') || ($depth === '256' && $kind !== 5) || ($depth === 'tc' && $kind !== 2)) {
                    throw new RuntimeException("$label: SGR $seq not allowed at depth $depth");
                }
                $i += $kind === 2 ? 4 : 2;
            }
        }
    }
}

/** Last frame of an animation (text after the final cursor-up), cursor codes removed. */
function lkLastFrame(string $ansi): string
{
    $parts = preg_split('/\e\[\d+A\r/', $ansi);
    return preg_replace('/\e\[\?25[hl]/', '', end($parts));
}

// ================================================================ animation

/**
 * Join encoded frames into an animation: hide cursor, frame, \e[<H>A\r,
 * frame, …, final static frame, show cursor. Play with logo-play.php.
 * @param list<string> $frames encoded frames (all the same height)
 */
function lkAnim(array $frames, string $static): string
{
    $h = count(explode("\n", rtrim($static, "\n")));
    $frames[] = $static;
    return "\e[?25l" . implode("\e[{$h}A\r", $frames) . "\e[?25h";
}

// ================================================================ output

/**
 * Verify + write `logo-<slug>-<depth>-<W>x<H>.ansi` into $dir and (optionally)
 * append its logos.jsonl line. Re-runs replace the existing jsonl line for
 * the same filename instead of duplicating it.
 * @return array{file:string,width:int,height:int}
 */
function lkWriteLogo(string $dir, string $slug, string $depth, string $ansi, string $description, array $tags, bool $jsonl = true): array
{
    [$w, $h] = lkVerify(lkLastFrame($ansi), "$slug/$depth");
    lkCheckDepth($ansi, $depth, "$slug/$depth");
    $name = "logo-$slug-$depth-{$w}x{$h}.ansi";
    file_put_contents("$dir/$name", $ansi);
    if ($jsonl) {
        $line = json_encode([
            'filename' => $name, 'slug' => $slug, 'colors' => $depth, 'width' => $w, 'height' => $h,
            'description' => $description, 'tags' => array_values($tags),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = "$dir/logos.jsonl";
        $fp = fopen($path, 'c+');
        flock($fp, LOCK_EX);
        $kept = [];
        foreach (explode("\n", stream_get_contents($fp)) as $l) {
            if ($l !== '' && (json_decode($l, true)['filename'] ?? null) !== $name) {
                $kept[] = $l;
            }
        }
        $kept[] = $line;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode("\n", $kept) . "\n");
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return ['file' => $name, 'width' => $w, 'height' => $h];
}
