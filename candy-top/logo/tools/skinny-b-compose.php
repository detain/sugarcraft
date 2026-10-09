<?php

declare(strict_types=1);

/**
 * skinny-b-compose — build a skinny logo by cutting rectangles out of EXISTING
 * .ansi logos and pasting them (verbatim, or re-rasterised to a new size) onto
 * a new canvas. Uses:
 *   - stack: cut "CANDY" and "TOP" from a 78-col logo, paste one over the other
 *   - crop: drop side ornaments / bezel labels / dead margins
 *   - condense: rescale a piece to fewer columns (box-filtered, re-rasterised as
 *     sextant / quadrant / half blocks, best two colours per cell)
 * Good as a quick draft or for ornament pieces (graphs, frames, scenery); small
 * lettering usually reads better from a hand-tuned generator copy. Look at
 * the PNG every time.
 *
 * Usage:
 *   php skinny-b-compose.php --canvas=WxH [--bg=#000000|none] \
 *       --in=SRC.ansi --piece=SPEC [--piece=SPEC ...] [--in=OTHER.ansi --piece=...] \
 *       (--out=FILE.ansi | --slug=SLUG [--dir=LOGOS_DIR] [--jsonl] [--desc=TEXT] [--tags=a,b]) \
 *       [--png=FILE.png] [--fade=N] [--mode=sextant|quadrant|half] [--mode16=hue|nearest]
 *
 *   SPEC = x,y,w,h@dx,dy[:WxH][/mode][~]
 *     x,y,w,h   source rectangle in cells (0-based) of the most recent --in
 *     @dx,dy    destination top-left cell on the canvas (may be negative)
 *     :WxH      rescale the piece to W×H cells (omit = copy cells verbatim)
 *     /mode     re-raster glyphs for this piece (default --mode, sextant)
 *     ~         transparent paste: blank cells (space, no bg) don't overwrite
 *   Pieces are pasted in order (later on top).
 *   --fade=N    darken fg+bg toward black over the outer N columns (edge vignette)
 *   --out       write one tc file (any name) — draft mode
 *   --slug      write logo-<slug>-{tc,256,16}-WxH.ansi via skbWriteSet (verified
 *               ≤40×5-15; 256 = nearest xterm, 16 = hue-preserving or nearest);
 *               --jsonl appends logos.jsonl lines (tag "skinny" added)
 *
 * Example (stack the words of a 78×10 logo into a 40×13 draft):
 *   php skinny-b-compose.php --canvas=40x13 --in=../logo-foo-tc-78x10.ansi \
 *     --piece=4,1,40,6@0,0:40x6 --piece=44,1,30,6@6,6:28x6 --out=/tmp/foo.ansi --png=/tmp/foo.png
 */

require_once __DIR__ . '/skinny-b-kit.php';

$o = ['canvas' => null, 'bg' => 'none', 'out' => null, 'slug' => null, 'dir' => dirname(__DIR__), 'jsonl' => false,
    'desc' => 'Skinny composite', 'tags' => '', 'png' => null, 'fade' => '0', 'mode' => 'sextant', 'mode16' => 'hue'];
$ops = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '-h' || $a === '--help') {
        skbHelp(__FILE__);
        exit(0);
    }
    if (!preg_match('/^--([a-z0-9]+)(?:=(.*))?$/', $a, $m)) {
        fwrite(STDERR, "bad arg $a (see --help)\n");
        exit(2);
    }
    if ($m[1] === 'in' || $m[1] === 'piece') {
        $ops[] = [$m[1], $m[2] ?? ''];
    } else {
        $o[$m[1]] = $m[2] ?? true;
    }
}
if (!$o['canvas'] || !preg_match('/^(\d+)x(\d+)$/', (string) $o['canvas'], $cm)) {
    fwrite(STDERR, "need --canvas=WxH (see --help)\n");
    exit(2);
}
[$W, $H] = [(int) $cm[1], (int) $cm[2]];
$canvas = skbBlank($W, $H, $o['bg'] === 'none' ? null : lkHex((string) $o['bg']));

$src = null;
foreach ($ops as [$kind, $val]) {
    if ($kind === 'in') {
        $src = skbLoadCells($val);
        continue;
    }
    if ($src === null) {
        fwrite(STDERR, "--piece before any --in\n");
        exit(2);
    }
    if (!preg_match('/^(\d+),(\d+),(\d+),(\d+)@(-?\d+),(-?\d+)(?::(\d+)x(\d+))?(?:\/(sextant|quadrant|half))?(~)?$/', $val, $p)) {
        fwrite(STDERR, "bad piece spec '$val' (see --help)\n");
        exit(2);
    }
    $piece = skbCrop($src, (int) $p[1], (int) $p[2], (int) $p[3], (int) $p[4]);
    if (($p[7] ?? '') !== '') {
        $piece = skbRescaleCells($piece, (int) $p[7], (int) $p[8], ($p[9] ?? '') !== '' ? $p[9] : (string) $o['mode']);
    }
    skbBlit($canvas, $piece, (int) $p[5], (int) $p[6], ($p[10] ?? '') === '~');
}

if (($fade = (int) $o['fade']) > 0) {
    foreach ($canvas as $y => $row) {
        foreach ($row as $x => $cell) {
            $k = min(1.0, (min($x, $W - 1 - $x) + 0.5) / $fade);
            if ($k < 1.0) {
                $canvas[$y][$x][1] = $cell[1] ? lkScale($cell[1], $k) : null;
                $canvas[$y][$x][2] = $cell[2] ? lkScale($cell[2], $k) : null;
            }
        }
    }
}

if ($o['slug']) {
    $tags = array_values(array_filter(explode(',', (string) $o['tags'])));
    $res = skbWriteSet((string) $o['dir'], (string) $o['slug'], skbEncodeDepths($canvas, [], (string) $o['mode16']), (string) $o['desc'], $tags, (bool) $o['jsonl']);
    $first = (string) $o['dir'] . '/' . $res[0]['file'];
} else {
    if (!$o['out']) {
        fwrite(STDERR, "need --out=FILE or --slug=SLUG\n");
        exit(2);
    }
    $first = (string) $o['out'];
    $ansi = lkEncode($canvas, 'tc');
    [$w, $h] = skbVerify($ansi, basename($first));
    file_put_contents($first, $ansi);
    fwrite(STDERR, "wrote $first ({$w}x{$h})\n");
}
if ($o['png']) {
    skbPreview($first, (string) $o['png']);
    fwrite(STDERR, "preview {$o['png']}\n");
}
