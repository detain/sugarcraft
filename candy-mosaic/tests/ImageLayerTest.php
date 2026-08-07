<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\PlacedImage;

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
}
