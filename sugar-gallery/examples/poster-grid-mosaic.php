<?php

declare(strict_types=1);

/**
 * PosterGrid × candy-mosaic — the real poster pipeline, no network.
 *
 *   php examples/poster-grid-mosaic.php
 *
 * The other example in this directory hand-writes its poster ANSI. This one is
 * the actual recipe a media app uses, end to end:
 *
 *   image bytes → ImageSource → Mosaic::render() → PosterCard::withPoster()
 *                                                  → PosterGrid::withItem()
 *
 * The images are synthesised with GD in memory so the demo runs offline, and one
 * poster is pre-seeded into a candy-mosaic DiskCache so you can watch the
 * promoted fetch policy (`indicesNeedingPoster()`) skip a cell whose bytes are
 * already on disk. Pixel renderers (sixel / iTerm2) are not cell text at all, so
 * the last section shows the other half of the seam: an ImageLayer placement and
 * the marker that stands in for it inside the frame.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Gallery\AnsiGuard;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;

const CARD_W = 18;
const POSTER_H = 6;

/**
 * A poster-shaped gradient with a per-title accent band, drawn with GD so the
 * mosaic renderer has real pixels to work with (and no network to wait on).
 */
function posterSource(int $r, int $g, int $b, int $accent): ImageSource
{
    $gd = imagecreatetruecolor(240, 340);
    for ($y = 0; $y < 340; $y++) {
        $f = 1.0 - 0.55 * ($y / 339);
        imageline($gd, 0, $y, 239, $y, imagecolorallocate($gd, (int) round($r * $f), (int) round($g * $f), (int) round($b * $f)));
    }
    imagefilledrectangle($gd, 0, 40 + 30 * ($accent % 5), 240, 90 + 30 * ($accent % 5), imagecolorallocate($gd, 245, 245, 245));

    try {
        return ImageSource::fromGd($gd, 'image/png');
    } finally {
        imagedestroy($gd);
    }
}

function heading(string $text): void
{
    echo "\n\x1b[1;36m" . $text . "\x1b[0m\n";
}

function dim(string $text): void
{
    echo "\x1b[2m" . $text . "\x1b[0m\n";
}

// ---------------------------------------------------------------------------
// 1. The grid right after a range fetch: cards exist, posters do not.
// ---------------------------------------------------------------------------

$titles = [
    'The Matrix'            => [0xB5, 0x17, 0x9E],
    'Blade Runner 2049'     => [0x3A, 0x0C, 0xA3],
    'Interstellar'          => [0x43, 0x61, 0xEE],
    'Arrival'               => [0x4C, 0xC9, 0xF0],
    'Dune: Part Two'        => [0xF7, 0x25, 0x85],
    '2001: A Space Odyssey' => [0x20, 0x24, 0x2B],
];

$cards = [];
$names = array_keys($titles);
foreach ($names as $i => $title) {
    // A posterUrl is all the grid needs to lay out a cell; the art arrives later.
    $cards[$i] = PosterCard::new((string) $i, $title, 'https://cdn.example/poster-' . $i . '.png');
}

$mosaic = Mosaic::halfBlock()->withScale(Scale::Fill);
$cache = new DiskCache(sys_get_temp_dir() . '/sugar-gallery-example-cache');

/** The GD poster for one item, so every renderer below sees identical art. */
$posterFor = static function (int $index) use ($titles): ImageSource {
    [$r, $g, $b] = array_values($titles)[$index];

    return posterSource($r, $g, $b, $index);
};

$grid = PosterGrid::new(cardWidth: CARD_W, posterHeight: POSTER_H, hSpacing: 2, vSpacing: 1)
    ->withViewport(60, 18)
    ->reset(total: count($cards))
    ->withItems($cards)
    ->moveTo(2);

// Pretend one poster was rendered in an earlier session and is already on disk.
$cachedIndex = 1;
$cache->put(
    DiskCache::key((string) $cards[$cachedIndex]->posterUrl, CARD_W, POSTER_H, $mosaic->protocol()),
    $mosaic->render($posterFor($cachedIndex), CARD_W, POSTER_H),
);

heading('  sugar-gallery · candy-mosaic poster pipeline (' . $mosaic->protocol() . ', inline=' . var_export($mosaic->isInline(), true) . ')');
dim('  ' . $grid->total() . ' items · 3×2 viewport · ▸ cursor · ░ skeleton');

heading('  1 · after the range fetch — nothing rendered yet');
echo $grid->render() . "\n";

// ---------------------------------------------------------------------------
// 2. The promoted fetch policy: which cells does the owner actually queue?
// ---------------------------------------------------------------------------

// The DiskCache probe is a plain callable over the card — the grid knows nothing
// about URLs, hosts, or image protocols, exactly as it should.
$isCached = static function (PosterCard $card) use ($cache, $mosaic): bool {
    return $card->posterUrl !== null
        && $cache->has(DiskCache::key($card->posterUrl, CARD_W, POSTER_H, $mosaic->protocol()));
};

$pending = $grid->indicesNeedingPoster(overscanRows: 1, isCached: $isCached);

heading('  2 · what the owner queues');
dim('  visible cards ......... ' . implode(', ', array_keys($grid->visibleCards(overscanRows: 1))));
dim('  already on disk ....... ' . $cachedIndex . '  (skipped by the probe, no promise round-trip)');
dim('  indicesNeedingPoster .. ' . implode(', ', $pending));

foreach ($pending as $index) {
    $bytes = $mosaic->render($posterFor($index), CARD_W, POSTER_H);
    $cache->put(DiskCache::key((string) $grid->item($index)->posterUrl, CARD_W, POSTER_H, $mosaic->protocol()), $bytes);

    // This is the whole async-fill seam: one immutable splice per resolved poster.
    $grid = $grid->withItem($index, $grid->item($index)->withPoster($bytes));
}

heading('  3 · after the per-cell fill — the same grid, painted');
echo $grid->render() . "\n";
dim('  pending now: [' . implode(', ', $grid->indicesNeedingPoster(overscanRows: 1, isCached: $isCached)) . ']');

// ---------------------------------------------------------------------------
// 3. Scrolling away prunes the sparse map; the cells fall back to skeletons.
// ---------------------------------------------------------------------------

// Grow the result set and let a second page land at the far end — the state of a
// library the user has been paging through, where the sparse map would otherwise
// only ever grow.
$tail = [];
foreach ([18, 19, 20, 21, 22, 23] as $index) {
    $name = $names[$index % count($names)];
    $tail[$index] = PosterCard::new((string) $index, $name)->withPoster($mosaic->render($posterFor($index % count($names)), CARD_W, POSTER_H));
}
$paged = $grid->withTotal(24)->withItems($tail);

$jumped = $paged->end();
[$start, $end] = $jumped->visibleRange(1);
$pruned = $jumped->withoutItemsOutside([$start, $end]);

heading('  4 · viewport eviction');
dim('  24 items; jumped to index ' . $pruned->cursorIndex() . ', visible window [' . $start . '..' . $end . ']');
dim('  loaded cards: ' . $paged->loadedCount() . ' → ' . $pruned->loadedCount() . ' (off-window art dropped)');
dim('  back at the top, page one is skeletons again — cheap to re-fetch, and the art is in DiskCache:');
echo $pruned->home()->render() . "\n";

// ---------------------------------------------------------------------------
// 4. Pixel renderers: the bytes never enter the text frame, a marker does.
// ---------------------------------------------------------------------------

$sixel = Mosaic::sixel();
if (!$sixel->isInline()) {
    heading('  5 · overlay mode (' . $sixel->protocol() . ') — art lives in an ImageLayer');

    $layer = new ImageLayer();
    $overlay = [];
    foreach ([0, 1, 2] as $index) {
        $bytes = $sixel->render($posterFor($index), CARD_W, POSTER_H);
        $placed = $layer->placeTracked($bytes, CARD_W, POSTER_H);
        // withImage(), not withPoster(): the card reserves the box, the runtime paints it.
        $overlay[$index] = PosterCard::new((string) $index, $names[$index])
            ->withImage($bytes, (int) $placed->imageId);
    }

    $overlayGrid = PosterGrid::new(cardWidth: CARD_W, posterHeight: POSTER_H)->withViewport(60, 18)
        ->reset(total: 3)->withItems($overlay);

    $overlayFrame = $overlayGrid->render();
    echo $overlayFrame . "\n";
    dim('  ' . count($layer->placements()) . ' placements; the frame holds '
        . (str_contains($overlayFrame, "\eP") ? 'DCS bytes (wrong!)' : 'only marker cells, no DCS payload')
        . ' — the runtime paints the art on top');
}

// ---------------------------------------------------------------------------
// 5. The styled-title trust boundary, both directions.
// ---------------------------------------------------------------------------

heading('  6 · styled titles: guard or sanitize');

$highlight = "\e[1;35mMa\e[0mtreeix";                       // self-produced: passes
$fromTheDb = "\e[1mMa\x1b[2Jtrix\e]8;;https://evil\x07!";  // untrusted: does not

dim('  plain title ........... ' . (new PosterCard('t', 'Matrix'))->title);
dim('  own bytes are safe .... ' . (AnsiGuard::isSafe($highlight) ? 'yes' : 'no') . ' → withStyledTitle($s, assertSafe: true)');
dim('  db bytes are safe ..... ' . (AnsiGuard::isSafe($fromTheDb) ? 'yes' : 'no') . ' → the guard would throw');
try {
    $grid = $grid->withItem(0, $grid->item(0)->withStyledTitle($fromTheDb, assertSafe: true));
} catch (InvalidArgumentException $e) {
    dim('  thrown: ' . $e->getMessage());
}

$safe = (new PosterCard('t', 'Matrix'))->withSafeStyledTitle($fromTheDb);
echo '  withSafeStyledTitle ... ' . $safe->render(false, CARD_W, 1) . "\n";
dim('  (the erase + hyperlink are gone, the colour survived)');

echo "\n";
