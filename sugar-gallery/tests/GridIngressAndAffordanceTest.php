<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;

/**
 * 2026-11-03 review batch: input-ingress validation (m1), stale-window reset
 * on shrink (n11), honest loadedCount (n20), the position affordance (M2) and
 * the viewport-lie detection seam (M14).
 */
final class GridIngressAndAffordanceTest extends TestCase
{
    private function grid(): PosterGrid
    {
        return PosterGrid::new(10, 4, 2, 1)->withViewport(48, 18);
    }

    private function card(string $id): PosterCard
    {
        return new PosterCard($id, 'Card ' . $id);
    }

    public function testAFailedCardIsReQueuedByTheFillPolicy(): void
    {
        // M4: a failed fetch is NOT a filled cell — the fill policy must keep
        // offering the index so a retry pass can attach art later. The card
        // carries stale art on purpose: only then is the failed short-circuit
        // the difference between 'filled' and re-queueable.
        $grid = PosterGrid::new(cardWidth: 10, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(21, 18)
            ->withTotal(2)
            ->withItem(0, PosterCard::new('0', 'Blade', 'https://cdn/0.png')->withPoster('ZZZZ')->withFailed())
            ->withItem(1, PosterCard::new('1', 'Dune', 'https://cdn/1.png')->withPoster('AAAA'));

        self::assertSame([0], $grid->indicesNeedingPoster(), 'the failed cell re-queues; the loaded cell does not');
    }

    public function testEmptyMessageResolvesLiveAndTheEmptyGridStillRendersNothing(): void
    {
        // m5: the notice exists for app chrome WITHOUT changing the render
        // contract — an empty grid's frame stays '' (pinned elsewhere).
        self::assertSame('(no posters)', PosterGrid::emptyMessage());
        self::assertSame('', $this->grid()->withTotal(0)->render());
    }

    public function testAStringShapedKeyIsRefusedAtTheIngress(): void
    {
        // PHP 8 evaluates 'abc' >= 0 as TRUE, so this key used to land in the
        // sparse map and explode later as a frame-wide TypeError in render().
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('withItems() expects int keys');

        $this->grid()->withItems(['abc' => $this->card('0')]);
    }

    public function testAWrongTypedValueIsRefusedAtTheIngress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('withItems() expects int keys');

        $this->grid()->withItems([3 => 'not-a-card']);
    }

    public function testNumericStringKeysStillLandAsRealInts(): void
    {
        // PHP casts numeric-string array keys to int at array-creation time;
        // the gate must not refuse what the engine already normalised.
        $grid = $this->grid()->withItems(['5' => $this->card('five')]);

        self::assertSame('five', $grid->item(5)?->id);
    }

    public function testNegativeIntentionalIndicesStillSkipQuietly(): void
    {
        $grid = $this->grid()->withItems([-1 => $this->card('ghost'), 0 => $this->card('zero')]);

        self::assertNull($grid->item(-1));
        self::assertSame('zero', $grid->item(0)?->id);
    }

    public function testEvictionWindowMustBeAPairOfInts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inclusive [int $start, int $end] pair');

        $this->grid()->withoutItemsOutside(['start' => 0, 'end' => 5]);
    }

    public function testNeedsFetchRefusesAnAssociatedPairAtTheIngress(): void
    {
        // sugar-gallery-004 (QA repro): hosts who name their tuple
        // ['start' => …, 'end' => …] hit unguarded [0]/[1] reads — null
        // bounds made needsFetch() answer TRUE for every window, a permanent
        // over-fetch loop instead of the documented dedup. The shared pair
        // law now refuses the shape at admission.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needsFetch() expects an inclusive [int $start, int $end] pair');

        $this->grid()->reset(10)->needsFetch(['start' => 1, 'end' => 2]);
    }

    public function testNeedsFetchRefusesATruncatedPair(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needsFetch() expects an inclusive');

        $this->grid()->reset(10)->needsFetch([5]);
    }

    public function testNeedsFetchStillAcceptsNullAndRealPairs(): void
    {
        // The guard must not eat the documented null ("nothing fetched yet")
        // or the honest [int, int] window.
        $grid = $this->grid()->reset(10);

        self::assertTrue($grid->needsFetch(null));
        self::assertFalse($grid->needsFetch([0, 20]));
    }

    public function testShrinkingToZeroResetsTheWindowInsteadOfFreezingIt(): void
    {
        $grid = $this->grid()->reset(20)->moveTo(15);
        self::assertSame(15, $grid->cursorIndex());

        $emptied = $grid->withTotal(0);
        self::assertSame(0, $emptied->cursorIndex(), 'no stale cursor survives an empty total');
        self::assertSame(0, $emptied->scrollRow());

        $grown = $emptied->withTotal(20);
        self::assertSame(0, $grown->cursorIndex(), 'the next widening starts at the top, not the old cursor');
    }

    public function testLoadedCountIgnoresEntriesPastTheEnd(): void
    {
        $grid = $this->grid()->reset(2);
        $grid = $grid->withItems([0 => $this->card('a'), 1 => $this->card('b')]);
        $grid = $this->grid()->reset(10)->withItems([
            0 => $this->card('a'), 1 => $this->card('b'), 9 => $this->card('j'), 14 => $this->card('past'),
        ]);

        self::assertSame(3, $grid->loadedCount(), 'only indices inside total count');
        // Shrinking keeps the past-the-end entry in the map (the host may widen
        // again) but it must never inflate the loaded figure.
        $shrunk = $grid->withTotal(10);
        self::assertSame(3, $shrunk->loadedCount());
    }

    public function testPositionTextReportsCursorOverTotal(): void
    {
        $grid = $this->grid()->reset(12)->moveTo(2);
        self::assertSame('  (3/12)', $grid->positionText());

        self::assertSame('', $grid->withTotal(0)->positionText(), 'empty grid prints nothing');
    }

    public function testFitsViewportDetectsSubCellScreens(): void
    {
        // cardWidth 10, posterHeight 4 → cellHeight 6.
        $grid = $this->grid()->reset(4);

        self::assertTrue($grid->fitsViewport());
        self::assertTrue($grid->fitsViewport(cols: 10, rows: 6), 'exactly one cell fits');
        self::assertFalse($grid->fitsViewport(cols: 9, rows: 18), 'column narrower than one card');
        self::assertFalse($grid->fitsViewport(cols: 48, rows: 5), 'row shorter than one cell');
        self::assertFalse($grid->fitsViewport(cols: 0, rows: 0), 'a zero viewport still renders — and lies');
    }
}
