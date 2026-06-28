<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageLayer;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;

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
}
