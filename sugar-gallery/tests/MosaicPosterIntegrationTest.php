<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Width;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Zone\Manager as ZoneManager;

/**
 * The sugar-gallery × candy-mosaic seam, proved with REAL renders.
 *
 * Every other suite in this lib feeds cards hand-written ANSI strings, which
 * proves the widget's bookkeeping but says nothing about whether a genuine
 * terminal image render survives the grid's width/height normalisation. These
 * tests build real pixel images in memory with GD (no network, no fixture files),
 * render them through candy-mosaic's own renderers, and push the bytes through
 * the documented recipe:
 *
 *     Mosaic::render() → PosterCard::withPoster()/withImage() → PosterGrid::withItem()
 *
 * Inline renderers (half/quarter block) land in the text frame; pixel renderers
 * (sixel/iTerm2) are not cell text at all, so they go through an
 * {@see ImageLayer} and the card carries only a marker. Both paths are covered,
 * because the grid must treat them identically.
 *
 * The geometry mirrors the real downstream consumer (a 14×9 poster card in a
 * 3-column window) so the alignment invariants are tested at the size shipped.
 */
final class MosaicPosterIntegrationTest extends TestCase
{
    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;

    /** 3 columns × 2 visible rows at the geometry above. */
    private const VIEWPORT_COLS = 60;
    private const VIEWPORT_ROWS = 23;

    /** Frame width of a full 3-column row: 3 cards + 2 inter-column gaps. */
    private const FRAME_WIDTH = 3 * self::CARD_WIDTH + 2 * self::H_SPACING;

    /** Skeleton glyphs in one cell: the grid paints posterHeight rows × cardWidth. */
    private const SKELETON_PIXELS = self::CARD_WIDTH * self::POSTER_HEIGHT;

    /** Distinct fills per poster, so content-keyed caches can never dedup two cells. */
    private const PALETTE = [
        [190, 60, 40],
        [40, 150, 90],
        [70, 60, 200],
        [200, 170, 40],
        [150, 40, 170],
        [40, 160, 190],
    ];

    private string $cacheDir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to synthesise poster images without a network.');
        }

        $this->cacheDir = sys_get_temp_dir() . '/sugar-gallery-mosaic-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->cacheDir);
    }

    // ---- inline renderers: the poster becomes cell text ------------------

    public function testHalfBlockPosterLandsInGridAndKeepsAlignment(): void
    {
        $grid = $this->grid(6)->withItems($this->filledCards(Mosaic::halfBlock(), [0, 1, 2, 3, 4, 5]));

        self::assertSame(6, $grid->loadedCount(), 'every spliced card is held at its absolute index');
        self::assertSame([0, 1, 2, 3, 4, 5], array_keys($grid->visibleCards()));

        $frame = $grid->render();
        self::assertStringNotContainsString('░', $frame, 'a fully loaded window holds no skeleton cell');
        self::assertFrameIsAligned($frame);
        self::assertSame(23, Layout::height($frame), '2 rows × cellHeight 11 + 1 vSpacing');
    }

    public function testQuarterBlockFillsTheSameCellAsHalfBlock(): void
    {
        // The grid must not care which inline renderer produced the bytes: both
        // occupy the identical reserved box.
        $half = $this->grid(6)->withItems($this->filledCards(Mosaic::halfBlock(), range(0, 5)))->render();
        $quarter = $this->grid(6)->withItems($this->filledCards(Mosaic::quarterBlock(), range(0, 5)))->render();

        self::assertStringNotContainsString('░', $quarter);
        self::assertFrameIsAligned($quarter);
        self::assertNotSame($half, $quarter, 'quarter block is a different rendering, not the same bytes');
        self::assertSame(Layout::height($half), Layout::height($quarter), 'same geometry, same frame height');

        $widths = array_map(static fn (string $line): int => Width::string($line), explode("\n", $quarter));
        self::assertSame(array_fill(0, count($widths), self::FRAME_WIDTH), $widths);
    }

    public function testOversizedRenderIsClippedToTheReservedCell(): void
    {
        // A poster asked for at more cells than the card reserves must never
        // inflate its row — the grid boxes it back down.
        $roomy = Mosaic::halfBlock()->withScale(Scale::Fill)->render($this->source(), 40, 40);
        self::assertGreaterThan(self::POSTER_HEIGHT, substr_count($roomy, "\n") + 1, 'the oversized render really is taller than the cell');

        $frame = $this->grid(1)->withItem(0, PosterCard::new('0', 'Tall')->withPoster($roomy))->render();

        self::assertFrameIsAligned($frame);
        self::assertSame(11, Layout::height($frame), 'one cell row, cropped to cellHeight');
    }

    public function testZoneIdsAreMarkedPerAbsoluteIndex(): void
    {
        $zones = ZoneManager::newGlobal();
        $grid = $this->grid(8)->withItems($this->filledCards(Mosaic::halfBlock(), [0, 1, 2]));

        $zones->scan($grid->render(true, $zones));

        self::assertNotNull($zones->get('cell:0'));
        self::assertNotNull($zones->get('cell:2'), 'a real poster is still hit-testable by its absolute index');
        self::assertNotNull($zones->get('cell:5'), 'a skeleton cell is hit-testable too');
        self::assertNull($zones->get('cell:99'), 'nothing is marked outside the rendered window');
    }

    // ---- the skeleton → filled transition --------------------------------

    public function testSkeletonThenFilledTransitionChangesOnlyTheLoadedCell(): void
    {
        $grid = $this->grid(6);
        $before = $grid->render();

        self::assertStringContainsString('░', $before, 'an absent index renders as a skeleton');
        self::assertSame(0, $grid->loadedCount());

        $poster = Mosaic::halfBlock()->render($this->source(0), self::CARD_WIDTH, self::POSTER_HEIGHT);
        $after = $grid->withItem(0, PosterCard::new('0', 'First')->withPoster($poster));
        $afterFrame = $after->render();

        self::assertNotSame($before, $afterFrame, 'splicing a real poster changes the frame');
        self::assertStringContainsString('░', $afterFrame, 'the cells still absent are still skeletons');
        self::assertSame(
            substr_count($before, '░') - self::SKELETON_PIXELS,
            substr_count($afterFrame, '░'),
            'exactly one cell stopped being a skeleton',
        );

        // The rest of the page splices in the same way, at absolute indices.
        $page = $after->withItems($this->filledCards(Mosaic::halfBlock(), [1, 2, 3, 4, 5]));
        self::assertSame(6, $page->loadedCount());
        self::assertStringNotContainsString('░', $page->render(), 'the whole visible window is now painted');

        // Index 9 is loaded but outside the window, so visibleCards() omits it.
        $withFar = $after->withItems($this->filledCards(Mosaic::halfBlock(), [1, 2, 3, 9]));
        self::assertSame(5, $withFar->loadedCount());
        self::assertSame([0, 1, 2, 3], array_keys($withFar->visibleCards()), 'the window bounds what is visible');
    }

    // ---- overlay renderers: the poster does NOT enter the frame -----------

    public function testSixelAndIterm2RideTheImageLayerNotTheTextFrame(): void
    {
        foreach ([Mosaic::sixel(), Mosaic::iterm2()] as $mosaic) {
            $protocol = $mosaic->protocol();
            self::assertFalse($mosaic->isInline(), $protocol . ' produces a pixel blob, not cell text');

            $layer = new ImageLayer();
            $cards = [];
            foreach ([0, 1, 2] as $index) {
                $bytes = $mosaic->render($this->source($index), self::CARD_WIDTH, self::POSTER_HEIGHT);
                $placed = $layer->placeTracked($bytes, self::CARD_WIDTH, self::POSTER_HEIGHT);
                self::assertNotNull($placed->imageId, $protocol . ' must report the id it assigned');

                $cards[$index] = PosterCard::new((string) $index, 'Item ' . $index)->withImage($bytes, $placed->imageId);
                self::assertTrue($cards[$index]->hasPoster(), 'an overlay fill counts as loaded');
            }

            $frame = $this->grid(6)->withItems($cards)->render();

            self::assertStringNotContainsString("\x1bP", $frame, $protocol . ' bytes must never reach the text frame');
            self::assertStringNotContainsString(']1337;', $frame, $protocol . ' bytes must never reach the text frame');
            self::assertStringContainsString(ImageOverlay::marker(0), $frame, 'the frame carries the marker the runtime paints');
            self::assertFrameIsAligned($frame);
            self::assertCount(3, $layer->placements(), 'three distinct posters get three placements');

            // An overlay fill counts as filled to the fetch policy exactly like an
            // inline one, even though the card holds no cell text.
            $mixed = $this->grid(6)->withItems($cards)->withItems($this->cardsWithUrls([3, 4, 5]));
            self::assertSame([3, 4, 5], $mixed->indicesNeedingPoster(), $protocol . '-filled cells drop out of the pending set');
        }
    }

    // ---- viewport eviction ------------------------------------------------

    public function testWithoutItemsOutsideBoundsTheSparseMapToTheLiveWindow(): void
    {
        $grid = $this->grid(60)->withItems($this->filledCards(Mosaic::halfBlock(), range(0, 5)));
        self::assertSame(6, $grid->loadedCount());

        // Scroll far away, then prune with a widened window around the new one.
        $scrolled = $grid->pageDown()->pageDown()->pageDown();
        [$start, $end] = $scrolled->visibleRange(1);
        $pruned = $scrolled->withoutItemsOutside([$start, $end]);

        self::assertNotSame($scrolled, $pruned, 'the first window is gone, so there is something to evict');
        self::assertSame(0, $pruned->loadedCount(), 'all six cards sat behind the new window');

        // A window that already covers everything is a no-op the caller detects by identity.
        self::assertSame($grid, $grid->withoutItemsOutside([0, 5]));
        self::assertSame(6, $grid->withoutItemsOutside([0, 5])->loadedCount());

        // Evicted cells render as skeletons again — the grid degrades, it never breaks.
        self::assertStringContainsString('░', $pruned->render());
        self::assertFrameIsAligned($pruned->render());
    }

    // ---- the promoted fetch-policy seam -----------------------------------

    public function testIndicesNeedingPosterFollowsTheVisibleWindow(): void
    {
        // 3 columns × 2 visible rows → the window is 0..5; index 8 sits one row below.
        $grid = $this->grid(60)->withItems([
            ...$this->cardsWithUrls(range(0, 5)),
            8 => PosterCard::new('8', 'Below', 'https://cdn.example/poster-8.png'),
        ]);

        self::assertSame(range(0, 5), $grid->indicesNeedingPoster(), 'the first two rows are exactly indices 0..5');
        self::assertSame(range(0, 5), $grid->indicesNeedingPoster(0), 'no overscan, no reach');

        // One overscan row reaches the next row of cards and no further.
        self::assertSame([0, 1, 2, 3, 4, 5, 8], $grid->indicesNeedingPoster(1), 'overscan widens the window to 0..8');
        self::assertNotContains(11, $grid->indicesNeedingPoster(1), 'two rows down is still not the owner\'s problem');

        // Splicing a real poster takes that cell out of the pending set.
        $filled = $grid->withItem(2, $grid->item(2)->withPoster(Mosaic::halfBlock()->render($this->source(2), self::CARD_WIDTH, self::POSTER_HEIGHT)));
        self::assertSame([0, 1, 3, 4, 5], $filled->indicesNeedingPoster());
    }

    public function testIndicesNeedingPosterHonoursFillableAndCacheProbes(): void
    {
        // Two loaded cells name artwork (one on a host this owner would reject),
        // one names nothing at all — and all three sit inside the window.
        $grid = $this->grid(60)->withItems([
            0 => PosterCard::new('0', 'Cdn', 'https://cdn.example/poster-0.png'),
            1 => PosterCard::new('1', 'Elsewhere', 'https://other.example/poster-1.png'),
            2 => PosterCard::new('2', 'No artwork'),
        ]);

        // Default predicate: any card naming a source qualifies; the artwork-less
        // one keeps its skeleton instead of being queued on every scroll.
        self::assertSame([0, 1], $grid->indicesNeedingPoster());

        // The owner's predicate REPLACES the default, so transport policy (a host
        // allow-list, a scheme gate) can be applied without the grid knowing any
        // URL rule itself.
        self::assertSame(
            [0],
            $grid->indicesNeedingPoster(0, static fn (PosterCard $card): bool => str_starts_with((string) $card->posterUrl, 'https://cdn.example/')),
        );

        // A caller that discovers its URL lazily (a detail fetch first) opts every
        // loaded cell in, artwork-less one included.
        self::assertSame([0, 1, 2], $grid->indicesNeedingPoster(0, static fn (PosterCard $card): bool => true));

        // The cache probe is renderer-agnostic: it only ever sees the card.
        self::assertSame([1], $grid->indicesNeedingPoster(0, null, static fn (PosterCard $card): bool => $card->id === '0'));
    }

    public function testDiskCacheProbeSkipsPostersAlreadyRendered(): void
    {
        $mosaic = Mosaic::halfBlock();
        $cache = new DiskCache($this->cacheDir);
        $cards = $this->cardsWithUrls([0, 1]);

        $key = DiskCache::key((string) $cards[0]->posterUrl, self::CARD_WIDTH, self::POSTER_HEIGHT, $mosaic->protocol());
        $bytes = $mosaic->render($this->source(0), self::CARD_WIDTH, self::POSTER_HEIGHT);
        $cache->put($key, $bytes);

        $probe = static function (PosterCard $card) use ($cache, $mosaic): bool {
            return $card->posterUrl !== null
                && $cache->has(DiskCache::key($card->posterUrl, self::CARD_WIDTH, self::POSTER_HEIGHT, $mosaic->protocol()));
        };

        $grid = $this->grid(2)->withItems($cards);
        self::assertSame([0, 1], $grid->indicesNeedingPoster(), 'without a probe both cells are queued');
        self::assertSame([1], $grid->indicesNeedingPoster(0, null, $probe), 'the cached poster is not re-queued');

        // The cached bytes are exactly the poster the grid paints.
        $filled = $grid->withItem(0, $cards[0]->withPoster((string) $cache->get($key)));
        self::assertSame([1], $filled->indicesNeedingPoster(0, null, $probe));
        self::assertStringNotContainsString('░', $filled->withItem(1, $cards[1]->withPoster($bytes))->render());
    }

    // ---- helpers ----------------------------------------------------------

    private function grid(int $total): PosterGrid
    {
        return PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::VIEWPORT_COLS, self::VIEWPORT_ROWS)
            ->reset($total);
    }

    /**
     * A real image with structure — a flat fill would let a renderer that ignored
     * the pixels pass as "rendered", and two identical posters would let a
     * content-keyed cache silently dedup them.
     */
    private function source(int $variant = 0): ImageSource
    {
        $width = 120;
        $height = 180;
        [$r, $g, $b] = self::PALETTE[$variant % count(self::PALETTE)];

        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $r, $g, $b));
        imagefilledrectangle($gd, 0, intdiv($height, 2), $width, $height, imagecolorallocate($gd, 255 - $r, 255 - $g, 255 - $b));
        imagefilledrectangle($gd, 0, 0, 8 + 9 * ($variant % 7), $height, imagecolorallocate($gd, 245, 245, 245));

        try {
            return ImageSource::fromGd($gd, 'image/png');
        } finally {
            imagedestroy($gd);
        }
    }

    /**
     * @param int[]        $indices
     *
     * @return array<int, PosterCard>
     */
    private function filledCards(Mosaic $mosaic, array $indices): array
    {
        $render = $mosaic->withScale(Scale::Fill);

        $cards = [];
        foreach ($indices as $index) {
            $cards[$index] = PosterCard::new((string) $index, 'Item ' . $index)
                ->withPoster($render->render($this->source($index), self::CARD_WIDTH, self::POSTER_HEIGHT));
        }

        return $cards;
    }

    /**
     * Cards naming a poster URL with no art yet — the state right after a range
     * fetch lands, before the per-cell async fill resolves.
     *
     * @param int[] $indices
     *
     * @return array<int, PosterCard>
     */
    private function cardsWithUrls(array $indices): array
    {
        $cards = [];
        foreach ($indices as $index) {
            $cards[$index] = PosterCard::new((string) $index, 'Item ' . $index, 'https://cdn.example/poster-' . $index . '.png');
        }

        return $cards;
    }

    /**
     * The grid's alignment contract: every line of a frame is the same visual
     * width, so an over- or under-sized poster can never shift a column.
     */
    private function assertFrameIsAligned(string $frame): void
    {
        self::assertNotSame('', $frame);
        $widths = array_unique(array_map(static fn (string $line): int => Width::string($line), explode("\n", $frame)));

        self::assertCount(1, $widths, 'every rendered line is the same visual width, got: ' . implode(',', $widths));
        self::assertSame(self::FRAME_WIDTH, reset($widths));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }
}
