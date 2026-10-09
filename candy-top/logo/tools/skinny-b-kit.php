<?php

declare(strict_types=1);

/**
 * skinny-b-kit — library for building SKINNY (≤40 col, 5-15 row) candy-top
 * logo variants. require_once it from a skinny generator or CLI. Function
 * prefix `skb` (does not clash with skinny-raster-kit.php's `sk*`, so both
 * can be loaded together).
 *
 * Built on logo-kit.php: a cell is [glyph, fg rgb|null, bg rgb|null], a wide
 * glyph is followed by a '' placeholder cell.
 *
 *  READ AN EXISTING LOGO
 *    skbParseAnsi($ansi) / skbLoadCells($file)   tc|256|16 ANSI → cells (last anim frame)
 *
 *  CUT / PASTE
 *    skbBlank($w,$h,$bg)  skbCrop($cells,$x,$y,$w,$h)  skbBlit(&$dst,$src,$dx,$dy,$transparent)
 *
 *  RE-RASTER AT ANOTHER SIZE (analytic, no GD): cells → sub-pixel image
 *  (understands █▀▄▌▐▔▕▏, ▁..▇, quadrants, sextants, braille dots, ░▒▓,
 *  ◢◣◤◥; other glyphs = fg/bg blend) → box resample → sextant/quadrant/half
 *    skbCellsToImage($cells)  skbResample($img,$w,$h)  skbImageToCells($img,$mode)
 *    skbRescaleCells($cells,$outW,$outH,$mode='sextant')
 *
 *  GENERATOR LAYOUT
 *    skbLayoutRows($innerW, [[text, glyphW, gap, wordGap, xOff?], ...])
 *        centred letter boxes per row → [['ch','x','row','letter'], ...]
 *        e.g. [['CANDY',6,1,0], ['TOP',6,1,0]] stacks CANDY over TOP.
 *    skbArgs($argv)  --write / --jsonl / --png=DIR / --out=DIR flags for generators
 *
 *  OUTPUT
 *    skbVerify($ansi,$label,$maxW=40,$minH=5,$maxH=15)   lkVerify + size limits
 *    skbEncodeDepths($cells,$pins16=[],$mode16='hue')     tc/256/16 via lkEncode
 *    skbWriteSet($dir,$slug,['tc'=>..,'256'=>..,'16'=>..],$desc,$tags,$jsonl,$descByDepth=[])
 *        verifies all depths first (equal widths, \e[0m, ≤40, depth purity),
 *        deletes stale logo-<slug>-<depth>-*x*.ansi of OTHER dimensions, writes
 *        logo-<slug>-<depth>-WxH.ansi, and with $jsonl APPENDS one logos.jsonl
 *        line per file (single FILE_APPEND|LOCK_EX write; skipped if that
 *        filename is already listed — the shared file is never rewritten).
 *        Adds the tag "skinny" automatically.
 *    skbPreview($ansiFile,$png,$previewer='mixed')   mixed|ttf|block-braille|braille|sextant|block
 *
 * Conventions: slug "<orig>-skinny" (anim "<orig>-skinny-anim"). Iterate
 * without --jsonl; add --jsonl on the final run. PNGs go to a scratch dir.
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-canvas.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/quadrant-raster.php';

const SKB_SUB_X = 4;    // sub-pixels per cell in skbCellsToImage
const SKB_SUB_Y = 24;   // divisible by 2, 3, 4, 8
const SKB_MAX_W = 40;

// ================================================================ parse

function skbParseAnsi(string $ansi): array
{
    $x256 = lkXterm256();
    $x16 = lkAnsi16();
    foreach ([30, 31, 32, 33, 34, 35, 36, 37] as $i => $c) {
        $x256[$i] = $x16[$c];
        $x256[$i + 8] = $x16[$c + 60];
    }
    $rows = [];
    foreach (explode("\n", rtrim(lkLastFrame($ansi), "\n")) as $line) {
        $fg = $bg = null;
        $row = [];
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = $bg = null;
                    } elseif ($v === 39) {
                        $fg = null;
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 2) {
                            $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                            $i += 4;
                        } else {
                            $c = $x256[$p[$i + 2]];
                            $i += 2;
                        }
                        $v === 38 ? $fg = $c : $bg = $c;
                    } elseif (($v >= 30 && $v <= 37) || ($v >= 90 && $v <= 97)) {
                        $fg = $x16[$v];
                    } elseif (($v >= 40 && $v <= 47) || ($v >= 100 && $v <= 107)) {
                        $bg = $x16[$v - 10];
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $row[] = [$g, $fg, $bg];
                if (mb_strwidth($g, 'UTF-8') === 2) {
                    $row[] = ['', $fg, $bg];
                }
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function skbLoadCells(string $file): array
{
    $s = @file_get_contents($file);
    if ($s === false) {
        throw new RuntimeException("cannot read $file");
    }
    return skbParseAnsi($s);
}

// ================================================================ cut / paste

function skbBlank(int $w, int $h, ?array $bg = null): array
{
    return lkBlankCells($w, $h, $bg);
}

function skbCrop(array $cells, int $x, int $y, int $w, int $h): array
{
    $out = [];
    for ($j = 0; $j < $h; $j++) {
        for ($i = 0; $i < $w; $i++) {
            $out[$j][$i] = $cells[$y + $j][$x + $i] ?? [' ', null, null];
        }
        if ($out[$j][0][0] === '') {
            $out[$j][0] = [' ', null, $out[$j][0][2]];          // orphan wide-glyph placeholder
        }
        $last = $out[$j][$w - 1][0];
        if ($last !== '' && mb_strwidth($last, 'UTF-8') === 2) {
            $out[$j][$w - 1] = [' ', null, $out[$j][$w - 1][2]];    // wide glyph cut in half
        }
    }
    return $out;
}

function skbBlit(array &$dst, array $src, int $dx, int $dy, bool $transparent = false): void
{
    foreach ($src as $j => $row) {
        foreach ($row as $i => $cell) {
            $y = $dy + $j;
            $x = $dx + $i;
            if (!isset($dst[$y][$x])) {
                continue;
            }
            if ($transparent && $cell[0] === ' ' && $cell[2] === null) {
                continue;
            }
            $dst[$y][$x] = $cell;
        }
    }
}

// ================================================================ re-raster

/** Glyph ink as SKB_SUB_X×SKB_SUB_Y bool grid, a float blend share, or null (no ink). */
function skbGlyphMask(string $g): array|float|null
{
    static $memo = [];
    if (array_key_exists($g, $memo)) {
        return $memo[$g];
    }
    $grid = static function (callable $f): array {
        $m = [];
        for ($y = 0; $y < SKB_SUB_Y; $y++) {
            for ($x = 0; $x < SKB_SUB_X; $x++) {
                $m[$y][$x] = (bool) $f(($x + 0.5) / SKB_SUB_X, ($y + 0.5) / SKB_SUB_Y);
            }
        }
        return $m;
    };
    if ($g === ' ' || $g === '' || $g === "\u{2800}") {
        return $memo[$g] = null;
    }
    $o = mb_ord($g, 'UTF-8');
    $simple = [
        '█' => fn ($x, $y) => true, '▀' => fn ($x, $y) => $y < 0.5, '▄' => fn ($x, $y) => $y >= 0.5,
        '▌' => fn ($x, $y) => $x < 0.5, '▐' => fn ($x, $y) => $x >= 0.5,
        '▔' => fn ($x, $y) => $y < 0.125, '▕' => fn ($x, $y) => $x >= 0.875, '▏' => fn ($x, $y) => $x < 0.125,
        '◢' => fn ($x, $y) => $x + $y >= 1, '◣' => fn ($x, $y) => $y >= $x, '◤' => fn ($x, $y) => $x + $y < 1, '◥' => fn ($x, $y) => $x >= $y,
    ];
    if (isset($simple[$g])) {
        return $memo[$g] = $grid($simple[$g]);
    }
    if ($o >= 0x2581 && $o <= 0x2587) {
        $k = ($o - 0x2580) / 8;
        return $memo[$g] = $grid(fn ($x, $y) => $y >= 1 - $k);
    }
    $qi = array_search($g, QR_GLYPHS, true);
    if ($qi !== false) {
        return $memo[$g] = $grid(fn ($x, $y) => (bool) ($qi & (($y < 0.5 ? 1 : 4) * ($x < 0.5 ? 1 : 2))));
    }
    $sb = scBits($g);
    if ($sb !== null) {
        return $memo[$g] = $grid(fn ($x, $y) => (bool) ($sb & (1 << (min(2, (int) ($y * 3)) * 2 + ($x < 0.5 ? 0 : 1)))));
    }
    if ($o >= 0x2800 && $o <= 0x28FF) {
        $bits = $o - 0x2800;
        $map = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];
        return $memo[$g] = $grid(function ($x, $y) use ($bits, $map) {
            $r = min(3, (int) ($y * 4));
            $fy = $y * 4 - $r;
            return ($bits & $map[$r][$x < 0.5 ? 0 : 1]) && $fy >= 0.2 && $fy < 0.8;
        });
    }
    $shade = ['░' => 0.25, '▒' => 0.5, '▓' => 0.75];
    if (isset($shade[$g])) {
        return $memo[$g] = $shade[$g];
    }
    return $memo[$g] = ($o >= 0x2500 && $o <= 0x257F) ? 0.3 : 0.35;
}

/** Cells → sub-pixel image (SKB_SUB_X × SKB_SUB_Y per cell); null bg stays null. */
function skbCellsToImage(array $cells, array $assumeBg = [0, 0, 0]): array
{
    $img = [];
    foreach ($cells as $cy => $row) {
        foreach (array_values($row) as $cx => [$g, $fg, $bg]) {
            $m = skbGlyphMask($g);
            $ink = $fg ?? [229, 229, 229];
            $blend = is_float($m) ? lkMix($bg ?? $assumeBg, $ink, $m) : null;
            for ($y = 0; $y < SKB_SUB_Y; $y++) {
                for ($x = 0; $x < SKB_SUB_X; $x++) {
                    $img[$cy * SKB_SUB_Y + $y][$cx * SKB_SUB_X + $x] = match (true) {
                        $m === null => $bg,
                        $blend !== null => $blend,
                        default => $m[$y][$x] ? $ink : $bg,
                    };
                }
            }
        }
    }
    return $img;
}

/** Area-average resample; a pixel is null when >50 % of its area is null. */
function skbResample(array $img, int $outW, int $outH): array
{
    $inH = count($img);
    $inW = count($img[0]);
    $sx = $inW / $outW;
    $sy = $inH / $outH;
    $out = [];
    for ($oy = 0; $oy < $outH; $oy++) {
        $y0 = $oy * $sy;
        $y1 = $y0 + $sy;
        for ($ox = 0; $ox < $outW; $ox++) {
            $x0 = $ox * $sx;
            $x1 = $x0 + $sx;
            $acc = [0.0, 0.0, 0.0];
            $wOp = $wAll = 0.0;
            for ($y = (int) floor($y0); $y < min($inH, (int) ceil($y1)); $y++) {
                $wy = min($y + 1, $y1) - max($y, $y0);
                for ($x = (int) floor($x0); $x < min($inW, (int) ceil($x1)); $x++) {
                    $w = $wy * (min($x + 1, $x1) - max($x, $x0));
                    if ($w <= 0) {
                        continue;
                    }
                    $wAll += $w;
                    if (($c = $img[$y][$x]) !== null) {
                        $acc[0] += $c[0] * $w;
                        $acc[1] += $c[1] * $w;
                        $acc[2] += $c[2] * $w;
                        $wOp += $w;
                    }
                }
            }
            $out[$oy][$ox] = ($wOp <= 0 || $wOp * 2 < $wAll) ? null
                : [(int) round($acc[0] / $wOp), (int) round($acc[1] / $wOp), (int) round($acc[2] / $wOp)];
        }
    }
    return $out;
}

/** Sub-pixels per cell for a raster mode: [x, y]. */
function skbModeRes(string $mode): array
{
    return match ($mode) {
        'sextant' => [2, 3], 'quadrant' => [2, 2], 'half' => [1, 2],
        default => throw new InvalidArgumentException("mode must be sextant|quadrant|half, got $mode"),
    };
}

function skbImageToCells(array $img, string $mode = 'sextant'): array
{
    skbModeRes($mode);
    return match ($mode) {
        'sextant' => siCells($img),
        'quadrant' => qrRaster($img),
        'half' => lkHalfBlock($img),
    };
}

function skbRescaleCells(array $cells, int $outW, int $outH, string $mode = 'sextant'): array
{
    [$rx, $ry] = skbModeRes($mode);
    return skbImageToCells(skbResample(skbCellsToImage($cells), $outW * $rx, $outH * $ry), $mode);
}

// ================================================================ layout / CLI

/**
 * Centre rows of fixed-width glyph boxes inside $innerW cells.
 * @param list<array> $rows [text, glyphW, gap, wordGap, xOffset=0]; glyphW may
 *        also be an array char=>width for proportional fonts.
 */
function skbLayoutRows(int $innerW, array $rows): array
{
    $out = [];
    $letter = 0;
    foreach ($rows as $r => $spec) {
        [$text, $gw, $gap, $wordGap] = $spec;
        $off = $spec[4] ?? 0;
        $chars = str_split($text);
        $wOf = static fn (string $c): int => $c === ' ' ? $wordGap : (is_array($gw) ? ($gw[$c] ?? $gw['*']) : $gw);
        $w = 0;
        foreach ($chars as $i => $ch) {
            $w += $wOf($ch) + ($ch !== ' ' && ($chars[$i + 1] ?? ' ') !== ' ' ? $gap : 0);
        }
        $x = intdiv($innerW - $w, 2) + $off;
        foreach ($chars as $i => $ch) {
            if ($ch !== ' ') {
                $out[] = ['ch' => $ch, 'x' => $x, 'row' => $r, 'letter' => $letter++];
            }
            $x += $wOf($ch) + ($ch !== ' ' && ($chars[$i + 1] ?? ' ') !== ' ' ? $gap : 0);
        }
    }
    return $out;
}

/** Common generator flags: --write --jsonl --png=DIR --out=DIR (default logos dir). */
function skbArgs(array $argv): array
{
    $a = ['write' => false, 'jsonl' => false, 'png' => null, 'out' => dirname(__DIR__), 'help' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
            $a[$m[1]] = $m[2] ?? true;
        } elseif ($arg === '-h') {
            $a['help'] = true;
        }
    }
    return $a;
}

/** Print the first docblock of $file (for --help). */
function skbHelp(string $file): void
{
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents($file), $m);
    echo preg_replace('/^ \* ?/m', '', $m[1] ?? ''), "\n";
}

// ================================================================ output

/** @return array{0:int,1:int} */
function skbVerify(string $ansi, string $label = 'skinny', int $maxW = SKB_MAX_W, int $minH = 5, int $maxH = 15): array
{
    [$w, $h] = lkVerify(lkLastFrame($ansi), $label);
    if ($w > $maxW) {
        throw new RuntimeException("$label: width $w > $maxW");
    }
    if ($h < $minH || $h > $maxH) {
        throw new RuntimeException("$label: height $h outside $minH..$maxH");
    }
    return [$w, $h];
}

/** @return array{tc:string, 256:string, 16:string} */
function skbEncodeDepths(array $cells, array $pins16 = [], string $mode16 = 'hue'): array
{
    return ['tc' => lkEncode($cells, 'tc'), '256' => lkEncode($cells, '256'), '16' => lkEncode($cells, '16', $pins16, $mode16)];
}

/**
 * @param array<string,string> $byDepth depth => ansi
 * @return list<array{file:string,width:int,height:int,depth:string}>
 */
function skbWriteSet(string $dir, string $slug, array $byDepth, string $desc, array $tags, bool $jsonl = false, array $descByDepth = []): array
{
    $dims = [];
    foreach ($byDepth as $depth => $ansi) {
        $dims[$depth] = skbVerify($ansi, "$slug/$depth");
        lkCheckDepth($ansi, (string) $depth, "$slug/$depth");
    }
    if (!in_array('skinny', $tags, true)) {
        $tags[] = 'skinny';
    }
    $path = "$dir/logos.jsonl";
    $have = is_file($path) ? (string) file_get_contents($path) : '';
    $done = [];
    foreach ($byDepth as $depth => $ansi) {
        [$w, $h] = $dims[$depth];
        $name = "logo-$slug-$depth-{$w}x{$h}.ansi";
        foreach (glob("$dir/logo-$slug-$depth-*x*.ansi") ?: [] as $old) {
            $b = basename($old);
            if ($b !== $name && preg_match('/^logo-' . preg_quote($slug, '/') . '-' . $depth . '-\d+x\d+\.ansi$/', $b)) {
                unlink($old);
                fwrite(STDERR, "  removed stale $b\n");
                if (str_contains($have, "\"filename\":\"$b\"")) {
                    fwrite(STDERR, "  WARNING: logos.jsonl still lists $b (shared file is not rewritten)\n");
                }
            }
        }
        file_put_contents("$dir/$name", $ansi);
        if ($jsonl && !str_contains($have, "\"filename\":\"$name\"")) {
            $line = json_encode([
                'filename' => $name, 'slug' => $slug, 'colors' => (string) $depth, 'width' => $w, 'height' => $h,
                'description' => $descByDepth[$depth] ?? "$desc ($depth)", 'tags' => array_values($tags),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        }
        $done[] = ['file' => $name, 'width' => $w, 'height' => $h, 'depth' => (string) $depth];
        fwrite(STDERR, "  wrote $name" . ($jsonl ? ' (+jsonl)' : '') . "\n");
    }
    return $done;
}

function skbPreview(string $ansiFile, string $png, string $previewer = 'mixed', int $cellW = 12, int $cellH = 24): void
{
    $tool = match ($previewer) {
        'mixed' => 'mixed-glyph-preview.php', 'ttf' => 'ansi-ttf-preview.php',
        'block-braille' => 'block-braille-ttf-preview.php', 'braille' => 'braille-preview.php',
        'sextant' => 'sextant-preview.php', 'block' => 'logo-preview.php',
        default => throw new InvalidArgumentException("unknown previewer $previewer"),
    };
    @mkdir(dirname($png), 0777, true);
    exec(sprintf('php %s %s %s %s %d %d 2>&1', escapeshellarg(__DIR__ . "/$tool"), escapeshellarg($ansiFile), escapeshellarg($png), escapeshellarg('#000000'), $cellW, $cellH), $o, $rc);
    if ($rc !== 0) {
        throw new RuntimeException('preview failed: ' . implode("\n", $o));
    }
}
