<?php

declare(strict_types=1);

/**
 * rock-candy-prism — "CANDY TOP" as faceted rock-candy crystals.
 *
 * Usage:
 *   php rock-candy-prism.php            preview tc + 256 + 16 to stdout
 *   php rock-candy-prism.php --write    write .ansi files into ../ (logos dir)
 *   php rock-candy-prism.php --write --jsonl   also append logos.jsonl lines
 *
 * Swap DESIGN DATA below (masks, palette, sparkles, shading levels) to make a
 * new faceted logo; the pipeline lives in rock-candy-prism-lib.php.
 */

require __DIR__ . '/rock-candy-prism-lib.php';

// ================================================================ DESIGN DATA

const RCP_SLUG = 'rock-candy-prism';

/** 8x12 pixel masks, '#' = crystal. Chamfered (45°) corners throughout. */
const RCP_GLYPHS = [
    'C' => [
        '..######', '.#######', '###.....', '##......', '##......', '##......',
        '##......', '##......', '##......', '###.....', '.#######', '..######',
    ],
    'A' => [
        '..####..', '.######.', '###..###', '##....##', '##....##', '########',
        '########', '##....##', '##....##', '##....##', '##....##', '##....##',
    ],
    'N' => [
        '###...##', '####..##', '#####.##', '##.##.##', '##.##.##', '##..####',
        '##..####', '##...###', '##...###', '##....##', '##....##', '##....##',
    ],
    'D' => [
        '######..', '#######.', '##...###', '##....##', '##....##', '##....##',
        '##....##', '##....##', '##....##', '##...###', '#######.', '######..',
    ],
    'Y' => [
        '##....##', '##....##', '###..###', '.######.', '..####..', '...##...',
        '...##...', '...##...', '...##...', '...##...', '...##...', '...##...',
    ],
    'T' => [
        '.######.', '########', '...##...', '...##...', '...##...', '...##...',
        '...##...', '...##...', '...##...', '...##...', '...##...', '...##...',
    ],
    'O' => [
        '..####..', '.######.', '###..###', '##....##', '##....##', '##....##',
        '##....##', '##....##', '##....##', '###..###', '.######.', '..####..',
    ],
    'P' => [
        '######..', '#######.', '##...###', '##....##', '##...###', '#######.',
        '######..', '##......', '##......', '##......', '##......', '##......',
    ],
];

const RCP_TEXT = 'CANDY TOP';
const RCP_LETTER_GAP = 1;   // px between letters
const RCP_WORD_GAP = 4;     // px for the space
const RCP_MARGIN = 1;       // blank columns left/right

/** One prism band hue per letter (spaces skipped), left → right. */
const RCP_HUES = ['#FF6EC7', '#FF8FA3', '#FFB27A', '#FFE66E', '#9CFFB0', '#5FE3E0', '#7FA8FF', '#B48CFF'];

/** Facet levels: 0 shadow, 1 mid face, 2 light face, 3 highlight. */
function rcpLevel(array $hue, int $level): array
{
    return match ($level) {
        0 => rcpScale($hue, 0.48),
        1 => $hue,
        2 => rcpMix($hue, [255, 255, 255], 0.46),
        default => rcpMix($hue, [255, 255, 255], 0.74),
    };
}

/** Sparkle row: col => [frame-final glyph, colour hex, twinkle phase]. */
const RCP_SPARKLES = [
    2 => ['✧', '#FFE9F7', 1], 9 => ['·', '#FFB6E1', 3], 17 => ['✦', '#FFFFFF', 0],
    27 => ['·', '#FFF3B0', 2], 35 => ['✧', '#E9FFF0', 3], 41 => ['·', '#C9FFF8', 1],
    52 => ['✦', '#FFFFFF', 2], 61 => ['·', '#D6E2FF', 0], 69 => ['✧', '#F1E6FF', 2],
    74 => ['·', '#FFE9F7', 3],
];

const RCP_DUST_BRIGHTNESS = 0.38;  // reflection row strength at centre
const RCP_ANIM_FRAMES = 14;
const RCP_ANIM_DELAY_MS = 110;     // player delay → 14 × 110 = 1.54 s
const RCP_TWINKLE = ['·', '✧', '✦', '✧'];

// ================================================================ BUILD

/** Lay out glyph masks → [mask rows, per-pixel letter index, letter x ranges]. */
function rcpLayout(): array
{
    $h = 12;
    $mask = array_fill(0, $h, '');
    $owner = [];
    $ranges = [];
    $li = 0;
    $chars = str_split(RCP_TEXT);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            for ($y = 0; $y < $h; $y++) {
                $mask[$y] .= str_repeat('.', RCP_WORD_GAP);
            }
            continue;
        }
        $g = RCP_GLYPHS[$ch];
        $x0 = strlen($mask[0]);
        $ranges[$li] = [$x0, $x0 + strlen($g[0])];
        for ($y = 0; $y < $h; $y++) {
            $mask[$y] .= $g[$y];
        }
        $li++;
        $next = $chars[$i + 1] ?? null;
        if ($next !== null && $next !== ' ') {
            for ($y = 0; $y < $h; $y++) {
                $mask[$y] .= str_repeat('.', RCP_LETTER_GAP);
            }
        }
    }
    $pad = str_repeat('.', RCP_MARGIN);
    foreach ($mask as $y => $row) {
        $mask[$y] = $pad . $row . $pad;
    }
    foreach ($ranges as $k => [$a, $b]) {
        $ranges[$k] = [$a + RCP_MARGIN, $b + RCP_MARGIN];
    }
    return [$mask, $ranges];
}

/** Prism hue at global pixel column x: interpolate between letter centres. */
function rcpHueAt(int $x, array $ranges): array
{
    $hues = array_map('rcpHex', RCP_HUES);
    $centres = array_map(static fn (array $r): float => ($r[0] + $r[1] - 1) / 2, $ranges);
    $n = count($centres);
    if ($x <= $centres[0]) {
        return $hues[0];
    }
    for ($i = 0; $i < $n - 1; $i++) {
        if ($x <= $centres[$i + 1]) {
            return rcpMix($hues[$i], $hues[$i + 1], ($x - $centres[$i]) / ($centres[$i + 1] - $centres[$i]));
        }
    }
    return $hues[$n - 1];
}

/**
 * Shade the mask into an rgb pixel grid. $glintX (nullable) positions the
 * animated diagonal glint band.
 */
function rcpPixels(array $mask, array $ranges, ?float $glintX = null): array
{
    $h = count($mask);
    $w = strlen($mask[0]);
    $on = static fn (int $x, int $y): bool => $y >= 0 && $y < $h && $x >= 0 && $x < $w && $mask[$y][$x] === '#';
    $px = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if (!$on($x, $y)) {
                $px[$y][$x] = null;
                continue;
            }
            $lx = 0.0;
            foreach ($ranges as [$a, $b]) {
                if ($x >= $a && $x < $b) {
                    $lx = ($x - $a + 0.5) / ($b - $a);
                }
            }
            // crystal facet: upper-left of the letter's anti-diagonal is the lit face
            $level = ($lx + ($y + 0.5) / $h) < 1.0 ? 2 : 1;
            $litEdge = !$on($x, $y - 1) || !$on($x - 1, $y);
            $darkEdge = !$on($x, $y + 1) || !$on($x + 1, $y);
            $level += ($litEdge ? 1 : 0) - ($darkEdge ? 1 : 0);
            $c = rcpLevel(rcpHueAt($x, $ranges), max(0, min(3, $level)));
            if ($glintX !== null) {
                $d = abs($x - ($glintX - $y * 0.6));
                if ($d < 2.6) {
                    $c = rcpMix($c, [255, 255, 255], 0.8 * (1 - $d / 5.2));
                }
            }
            $px[$y][$x] = $c;
        }
    }
    return $px;
}

/** Full cell grid: sparkle row + 6 crystal rows + reflection row. */
function rcpCells(?float $glintX = null, ?int $frame = null): array
{
    [$mask, $ranges] = rcpLayout();
    $w = strlen($mask[0]);
    $glyphs = [];
    foreach (RCP_SPARKLES as $x => [$g, $hex, $phase]) {
        if ($frame !== null) {
            $g = RCP_TWINKLE[($frame + $phase) % 4];
        }
        $glyphs[$x] = [$g, rcpHex($hex)];
    }
    $cells = [rcpTextRow($w, $glyphs)];
    foreach (rcpHalfBlock(rcpPixels($mask, $ranges, $glintX)) as $row) {
        $cells[] = $row;
    }
    // sugar-dust reflection: ▔ under every lit bottom pixel, fading to the ends
    $dust = [];
    $mid = ($w - 1) / 2;
    $last = $mask[count($mask) - 1];
    for ($x = 0; $x < $w; $x++) {
        if ($last[$x] === '#') {
            $fade = 1 - 0.55 * abs($x - $mid) / $mid;
            $dust[$x] = ['▔', rcpScale(rcpHueAt($x, $ranges), RCP_DUST_BRIGHTNESS * $fade + 0.12)];
        }
    }
    $cells[] = rcpTextRow($w, $dust);
    return $cells;
}

/** Animated stream: hidden cursor, glint sweep, ends on the static frame. */
function rcpAnim(string $depth): string
{
    $static = rcpEncode(rcpCells(), $depth);
    $height = count(explode("\n", rtrim($static, "\n")));
    [$mask] = rcpLayout();
    $w = strlen($mask[0]);
    $out = "\e[?25l";
    for ($f = 0; $f < RCP_ANIM_FRAMES - 1; $f++) {
        $gx = -4 + ($w + 12) * $f / (RCP_ANIM_FRAMES - 2);
        if ($f > 0) {
            $out .= "\e[{$height}A\r";
        }
        $out .= rcpEncode(rcpCells($gx, $f), $depth);
    }
    return $out . "\e[{$height}A\r" . $static . "\e[?25h";
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$jsonl = in_array('--jsonl', $argv, true);
$dir = dirname(__DIR__);

$desc = [
    'tc' => 'CANDY TOP as faceted rock-candy crystals: chamfered half-block letters with 4-level bevel/facet shading, a pastel prism gradient (bubblegum→peach→lemon→mint→aqua→lilac), a sparkle row above and a fading sugar-dust reflection below. 24-bit colour.',
    '256' => 'rock-candy-prism downgraded to xterm-256 (nearest cube/grey with hue-weighted distance); faceted pastel crystal letters, sparkles and reflection.',
    '16' => 'rock-candy-prism in 16 ANSI colours: hue-bucketed facets (bright white highlights, bright hue faces, normal-hue shadows) with sparkles and dust reflection.',
];
$tags = ['rock-candy', 'faceted-crystal', 'prismatic-gradient', 'half-block-pixel', 'bevel-shading', 'sparkle', 'pastel'];

foreach (['tc', '256', '16'] as $depth) {
    $ansi = rcpEncode(rcpCells(), $depth);
    [$w, $h] = rcpVerify($ansi, "$depth static");
    echo $ansi;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    $files = [["logo-" . RCP_SLUG . "-$depth-{$w}x{$h}.ansi", RCP_SLUG, $ansi, $desc[$depth], $tags]];
    $anim = rcpAnim($depth);
    // verify the final (static) frame of the animation
    $tail = substr($anim, strrpos($anim, "\e[{$h}A\r") + strlen("\e[{$h}A\r"));
    rcpVerify(str_replace("\e[?25h", '', $tail), "$depth anim");
    $files[] = [
        "logo-" . RCP_SLUG . "-anim-$depth-{$w}x{$h}.ansi", RCP_SLUG . '-anim', $anim,
        'Animated rock-candy-prism (' . $depth . '): a white glint sweeps diagonally across the crystal letters while sparkles twinkle, '
            . RCP_ANIM_FRAMES . ' frames; play with tools/rock-candy-prism-play.php (~' . round(RCP_ANIM_FRAMES * RCP_ANIM_DELAY_MS / 1000, 2)
            . 's), ends on the static logo with cursor restored.',
        array_merge($tags, ['animated', 'glint-sweep', 'twinkle']),
    ];
    if (!$write) {
        continue;
    }
    foreach ($files as [$name, $slug, $data, $d, $t]) {
        file_put_contents("$dir/$name", $data);
        fwrite(STDERR, "  wrote $name\n");
        if ($jsonl) {
            $line = json_encode([
                'filename' => $name, 'slug' => $slug, 'colors' => $depth, 'width' => $w, 'height' => $h,
                'description' => $d, 'tags' => $t,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents("$dir/logos.jsonl", $line, FILE_APPEND | LOCK_EX);
        }
    }
}
