<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Image\ImageLayer;
use SugarCraft\Image\PlacedImage;

final class ImageLayerTest extends TestCase
{
    public function testPlaceReturnsAMarkerBlockAndRegistersThePlacement(): void
    {
        $layer = new ImageLayer();
        $block = $layer->place('SIXELBYTES', 6, 3);

        self::assertCount(3, explode("\n", $block));
        self::assertStringContainsString(ImageOverlay::marker(0), $block);
        self::assertStringNotContainsString('SIXELBYTES', $block, 'bytes stay out of the frame');

        $placements = $layer->placements();
        self::assertArrayHasKey(0, $placements);
        self::assertInstanceOf(ImagePlacement::class, $placements[0]);
        self::assertSame('SIXELBYTES', $placements[0]->bytes);
        self::assertSame(6, $placements[0]->widthCells);
        self::assertSame(3, $placements[0]->heightCells);
        self::assertFalse($layer->isEmpty());
    }

    public function testIdenticalBytesReuseTheSameId(): void
    {
        $layer = new ImageLayer();
        $a = $layer->place('SAME', 4, 2);
        $b = $layer->place('SAME', 4, 2);

        self::assertSame($a, $b, 'same content → same id → same block');
        self::assertCount(1, $layer->placements());
    }

    public function testDistinctBytesGetDistinctIdsAndPaints(): void
    {
        $layer = new ImageLayer();
        $first = $layer->place('ONE', 4, 1);
        $second = $layer->place('TWO', 4, 1);

        self::assertNotSame($first, $second);
        self::assertSame(['ONE', 'TWO'], array_map(static fn (ImagePlacement $p): string => $p->bytes, $layer->placements()));

        // Both markers resolve against the layer's placements.
        [, $paints] = ImageOverlay::resolve($first . "\n" . $second, $layer->placements());
        self::assertSame('ONE', $paints[0]['bytes']);
        self::assertSame('TWO', $paints[1]['bytes']);
    }

    public function testEmptyLayerByDefault(): void
    {
        self::assertTrue((new ImageLayer())->isEmpty());
        self::assertSame([], (new ImageLayer())->placements());
    }

    public function testPlaceTrackedReportsTheFirstIdAndTheSameMarkerAsPlace(): void
    {
        $placed = (new ImageLayer())->placeTracked('SIXELBYTES', 6, 3);

        self::assertInstanceOf(PlacedImage::class, $placed);
        self::assertSame(0, $placed->imageId);

        // The non-breaking guarantee: place() is a thin delegate, so the marker
        // a frame-composition caller gets is byte-for-byte what it always was.
        self::assertSame((new ImageLayer())->place('SIXELBYTES', 6, 3), $placed->marker);
    }

    public function testPlaceTrackedRegistersThePlacementJustLikePlace(): void
    {
        $layer = new ImageLayer();
        $placed = $layer->placeTracked('SIXELBYTES', 6, 3);

        self::assertStringContainsString(ImageOverlay::marker(0), $placed->marker);
        self::assertStringNotContainsString('SIXELBYTES', $placed->marker, 'bytes stay out of the frame');

        $placements = $layer->placements();
        self::assertArrayHasKey(0, $placements);
        self::assertSame('SIXELBYTES', $placements[0]->bytes);
        self::assertFalse($layer->isEmpty());
    }

    public function testPlaceTrackedReturnsTheOriginalIdOnADedupHit(): void
    {
        $layer = new ImageLayer();

        self::assertSame(0, $layer->placeTracked('ONE', 4, 1)->imageId);
        self::assertSame(1, $layer->placeTracked('TWO', 4, 1)->imageId);
        self::assertSame(2, $layer->placeTracked('THREE', 4, 1)->imageId);

        // Re-placing the FIRST image must report id 0, not a fresh id and not
        // the highest one. `??=` returns the existing value and re-assigning an
        // existing key keeps its original insertion position, so the id cannot
        // be recovered from the placement order.
        $again = $layer->placeTracked('ONE', 4, 1);
        self::assertSame(0, $again->imageId);
        self::assertCount(3, $layer->placements(), 'a dedup hit adds no placement');

        // This is precisely why placeTracked() exists: the obvious workaround
        // disagrees with the truth here.
        self::assertSame(2, array_key_last($layer->placements()));
        self::assertNotSame(array_key_last($layer->placements()), $again->imageId);

        // Same id → same marker as the original placement.
        self::assertSame(ImageOverlay::markerBlock(0, 4, 1), $again->marker);
    }

    public function testPlaceTrackedGivesDistinctBytesAscendingIds(): void
    {
        $layer = new ImageLayer();
        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $bytes) {
            $ids[] = $layer->placeTracked($bytes, 2, 1)->imageId;
        }

        self::assertSame([0, 1, 2, 3], $ids);
    }

    public function testPlaceTrackedReportsNullIdAndABlankMarkerOnceTheIdSpaceIsExhausted(): void
    {
        $layer = new ImageLayer();
        for ($i = 0; $i < ImageOverlay::MAX_IMAGES; $i++) {
            self::assertSame($i, $layer->placeTracked(pack('N', $i), 1, 1)->imageId);
        }
        self::assertCount(ImageOverlay::MAX_IMAGES, $layer->placements());

        // Id MAX_IMAGES would fall outside the PUA marker window.
        $overflow = $layer->placeTracked(pack('N', ImageOverlay::MAX_IMAGES), 6, 3);

        self::assertNull($overflow->imageId);
        self::assertSame("      \n      \n      ", $overflow->marker, 'a 6x3 block of spaces');
        self::assertCount(ImageOverlay::MAX_IMAGES, $layer->placements(), 'no placement is recorded');

        // place() degrades the same way it always did — a blank block, no throw.
        self::assertSame($overflow->marker, $layer->place(pack('N', ImageOverlay::MAX_IMAGES), 6, 3));
    }

    public function testRemoveById(): void
    {
        $layer = new ImageLayer();

        // Place an image
        $placed = $layer->placeTracked('image bytes', 100, 50);
        self::assertCount(1, $layer->placements());
        self::assertArrayHasKey($placed->imageId, $layer->placements());

        // Remove it
        $layer->removeById($placed->imageId);
        self::assertCount(0, $layer->placements());
        self::assertArrayNotHasKey($placed->imageId, $layer->placements());
    }

    public function testRemoveByIdIsIdempotent(): void
    {
        $layer = new ImageLayer();
        $placed = $layer->placeTracked('image bytes', 100, 50);

        // Removing twice should not error
        $layer->removeById($placed->imageId);
        $layer->removeById($placed->imageId); // Should be safe

        self::assertCount(0, $layer->placements());
    }

    public function testRemoveByIdOnNonExistentIdIsSafe(): void
    {
        $layer = new ImageLayer();

        // Removing an id that was never placed should not error
        $layer->removeById(999);

        self::assertTrue($layer->isEmpty());
    }

    public function testRemoveByIdAndPlaceAgain(): void
    {
        $layer = new ImageLayer();

        $first = $layer->placeTracked('A', 2, 1);
        self::assertSame(0, $first->imageId);
        self::assertCount(1, $layer->placements());

        $layer->removeById($first->imageId);
        self::assertCount(0, $layer->placements());

        // New placement gets a fresh id (dedup is based on bytes, not id reuse)
        $second = $layer->placeTracked('B', 2, 1);
        self::assertSame(1, $second->imageId);
        self::assertCount(1, $layer->placements());
    }

    public function testExhaustionNeverGrowsTheDigestTable(): void
    {
        $layer = new ImageLayer();
        $cap   = ImageOverlay::MAX_IMAGES;

        for ($i = 0; $i < $cap; $i++) {
            self::assertSame($i, $layer->placeTracked("blob-{$i}", 1, 1)->imageId);
        }

        $table = new \ReflectionProperty(ImageLayer::class, 'idByDigest');
        self::assertCount($cap, $table->getValue($layer));

        // Past the cap: blank blocks and null ids, and NO new digest rows.
        // The old `??=` wrote one dead row per unique overflow blob, forever
        // (lane p2, N5 — the only unbounded structure in the class).
        for ($i = 0; $i < 50; $i++) {
            self::assertNull($layer->placeTracked("overflow-{$i}", 1, 1)->imageId);
        }
        self::assertCount($cap, $table->getValue($layer), 'idByDigest grew past MAX_IMAGES');

        // Registered content still dedupes after exhaustion.
        self::assertSame(7, $layer->placeTracked('blob-7', 1, 1)->imageId);
    }

    public function testTheFirstIdsCellInFrameTextIsNotAMarker(): void
    {
        // Audit 15b-17: ids are dense from 0, so U+E002 used to mean "this
        // frame's first picture" wherever model text put it. The block the
        // layer hands back is the only thing that paints; the same cell typed
        // into text next to it is left as a glyph.
        $layer = new ImageLayer();
        $block = $layer->place('PIXELS', 3, 2);
        $frame = $block . "\nreply: \u{E002} and \u{E0B0}";

        [$body, $paints] = ImageOverlay::resolve($frame, $layer->placements());

        self::assertCount(1, $paints);
        self::assertSame([1, 1], [$paints[0]['row'], $paints[0]['col']]);
        self::assertStringEndsWith("reply: \u{E002} and \u{E0B0}", $body);
    }

    // ---- SI-23: id-space reclamation (findings 017 + 097) -------------------

    public function testReleaseReturnsTheIdToThePoolForNewContent(): void
    {
        $layer = new ImageLayer();
        self::assertSame(0, $layer->placeTracked('A', 1, 1)->imageId);
        self::assertSame(1, $layer->placeTracked('B', 1, 1)->imageId);
        self::assertSame(2, $layer->placeTracked('C', 1, 1)->imageId);

        // Old count-based allocation handed D id 3 and left 1 stranded; the
        // gap-walk door sees 1 as the lowest unclaimed id the moment B's
        // release forfeits its claim.
        $layer->release(1);
        self::assertSame(1, $layer->placeTracked('D', 1, 1)->imageId, 'a released id must be reused by the next new blob');

        // And B, claim-free now, re-registers — at the next free hole.
        self::assertSame(3, $layer->placeTracked('B', 1, 1)->imageId, 'released bytes re-place as new content');
    }

    public function testScrollingChurnCyclesTheFreedWindowIdsInsteadOfBurningThem(): void
    {
        // The viewport use case from finding 017: one image in view per
        // frame, every older one released. Ascending-only allocation burned
        // MAX_IMAGES ids and wedged the layer on blank blocks after a few
        // thousand scrolls; the free-id walk keeps the live ids alternating
        // inside the handful of cells the window actually holds.
        $layer = new ImageLayer();
        $ids   = [];

        for ($tick = 0; $tick < 10; $tick++) {
            $placed = $layer->placeTracked("tick-{$tick}", 2, 1);
            $ids[]  = $placed->imageId;
            $layer->releaseAllExcept([$placed->imageId]);
        }

        self::assertSame([0, 1, 0, 1, 0, 1, 0, 1, 0, 1], $ids, 'churn must ride the freed ids, never climb');
        self::assertCount(1, $layer->placements());
    }

    public function testRemoveByIdHoldsTheIdClaimTheTerminalStillNeeds(): void
    {
        // The paired door: removeById emits NO delete sequence, the terminal
        // still paints those bytes — so the claim stays, the id is NOT fed
        // back to the pool, and re-placing the bytes returns it.
        $layer = new ImageLayer();
        self::assertSame(0, $layer->placeTracked('A', 1, 1)->imageId);
        self::assertSame(1, $layer->placeTracked('B', 1, 1)->imageId);

        $layer->removeById(0);
        self::assertSame(2, $layer->placeTracked('C', 1, 1)->imageId, 'a removed-but-not-released id stays claimed');
        self::assertSame(0, $layer->placeTracked('A', 1, 1)->imageId, 'the held claim answers the re-place');
    }

    public function testNonPositiveFootprintsNormalizeToThePaintedSize(): void
    {
        $layer = new ImageLayer();
        $placed = $layer->placeTracked('TINY', -2, 0);

        self::assertSame(0, $placed->imageId);
        self::assertStringNotContainsString("\n", $placed->marker, 'the marker leg paints the 1x1 it is clamped to');

        $placement = $layer->placements()[0];
        self::assertSame(1, $placement->widthCells);
        self::assertSame(1, $placement->heightCells, 'the stored footprint must be the drawn one');

        self::assertSame(0, $layer->imageIdForDigest(ImageLayer::digestFor('TINY', 1, 1)));
        self::assertNull($layer->imageIdForDigest(ImageLayer::digestFor('TINY', -2, 0)), 'bogus dims key nothing the layer could paint');

        // Both legs of the exhaustion fork share the normalized size: the
        // blank leg (private door, reached in production only when the id
        // space is spent) collapses to the same 1x1 geometry the marker leg
        // painted — same row count, same one-cell row.
        $blank = new \ReflectionMethod(ImageLayer::class, 'blankBlock');
        $blank->setAccessible(true);
        $blankBlock = (string) $blank->invoke(null, -2, 0);

        self::assertSame(' ', $blankBlock);
        self::assertCount(1, explode("\n", $placed->marker), 'bogus dims must not widen the marker leg');
        self::assertSame(count(explode("\n", $placed->marker)), count(explode("\n", $blankBlock)), 'both legs agree on rows for the same bogus request');
    }
}
