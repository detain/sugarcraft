<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\KittyOptions;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

final class KittyOptionsTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testTransmitIncludesImageIdInBeginSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(42);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Begin must carry i=42
        $this->assertStringContainsString('i=42', $out);
        // Place action must NOT be present
        $this->assertStringNotContainsString('a=p', $out);
    }

    public function testTransmitWithZIndexIncludesZInBeginSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withZIndex(5);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('z=5', $out);
    }

    public function testPlaceActionEmitsPlaceSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::place(imageId: 7, x: 2, y: 1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Should contain a=p (place action) and i=7 and x=2 y=1
        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=7', $out);
        $this->assertStringContainsString('x=2', $out);
        $this->assertStringContainsString('y=1', $out);
        // Should NOT have chunk data (no base64 data in place mode)
        $this->assertStringNotContainsString(',PHN2', $out); // no base64 PNG header
    }

    public function testPlaceWithoutOffsetOmitsXY(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::place(3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // a=p and i=3 must be present, but x= and y= should not appear
        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=3', $out);
        $this->assertStringNotContainsString('x=', $out);
        $this->assertStringNotContainsString('y=', $out);
    }

    public function testWithCompressionSetsCompression(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1); // zlib

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // f=1 means zlib compression
        $this->assertStringContainsString('f=1', $out);
    }

    public function testKittyOptionsToArrayOmitsZeroDefaults(): void
    {
        $opts = KittyOptions::transmit(0);
        $arr  = $opts->toArray();

        // imageId=0 should be omitted (null)
        $this->assertNull($arr['i']);
        // z=0 should be omitted
        $this->assertNull($arr['z']);
        // f=100 (no compression) should be omitted
        $this->assertNull($arr['f']);
    }

    public function testZIndexCanBeNegative(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withZIndex(-3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('z=-3', $out);
    }
}
