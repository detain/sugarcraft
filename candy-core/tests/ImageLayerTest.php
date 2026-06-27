<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageLayer;
use SugarCraft\Core\ImageOverlay;

final class ImageLayerTest extends TestCase
{
    public function testPlaceReturnsAMarkerBlockAndRegistersTheBytes(): void
    {
        $layer = new ImageLayer();
        $block = $layer->place('SIXELBYTES', 6, 3);

        self::assertCount(3, explode("\n", $block));
        self::assertStringContainsString(ImageOverlay::marker(0), $block);
        self::assertStringNotContainsString('SIXELBYTES', $block, 'bytes stay out of the frame');
        self::assertSame([0 => 'SIXELBYTES'], $layer->blobs());
        self::assertFalse($layer->isEmpty());
    }

    public function testIdenticalBytesReuseTheSameId(): void
    {
        $layer = new ImageLayer();
        $a = $layer->place('SAME', 4, 2);
        $b = $layer->place('SAME', 4, 2);

        self::assertSame($a, $b, 'same content → same id → same block');
        self::assertCount(1, $layer->blobs());
    }

    public function testDistinctBytesGetDistinctIdsAndPaints(): void
    {
        $layer = new ImageLayer();
        $first = $layer->place('ONE', 4, 1);
        $second = $layer->place('TWO', 4, 1);

        self::assertNotSame($first, $second);
        self::assertSame([0 => 'ONE', 1 => 'TWO'], $layer->blobs());

        // Both markers resolve against the layer's blob map.
        [, $paints] = ImageOverlay::resolve($first . "\n" . $second, $layer->blobs());
        self::assertSame('ONE', $paints[0]['bytes']);
        self::assertSame('TWO', $paints[1]['bytes']);
    }

    public function testEmptyLayerByDefault(): void
    {
        self::assertTrue((new ImageLayer())->isEmpty());
        self::assertSame([], (new ImageLayer())->blobs());
    }
}
