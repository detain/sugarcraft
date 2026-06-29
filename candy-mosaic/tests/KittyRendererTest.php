<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

final class KittyRendererTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new KittyRenderer();
    }

    public function testName(): void
    {
        $this->assertSame('kitty', $this->renderer->name());
    }

    public function testSupportsAlpha(): void
    {
        $this->assertTrue($this->renderer->supportsAlpha());
    }

    public function testRendersKittyBeginSequence(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        // Begins with DCS q (Kitty graphics begin) + width/height params.
        $this->assertStringStartsWith("\x1bPq", $out);
        $this->assertStringContainsString('c=8', $out);    // cell columns
        $this->assertStringContainsString('r=4', $out);    // cell rows
    }

    public function testRendersKittyEndSequence(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        // Terminates with ST (String Terminator).
        $this->assertStringEndsWith("\x1b\\", $out);
    }

    public function testPayloadIsBase64Png(): void
    {
        // ImageSource::fromFile re-encodes via GD, so use that re-encoded PNG bytes.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        $b64 = base64_encode($source->bytes);
        $this->assertStringContainsString($b64, $out);
    }

    public function testEffectiveHeightComputedFromAspectRatio(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        // Source aspect ratio = 2 (8/4). With width=10, expected height=5.
        $out = $this->renderer->render($source, 10);

        $this->assertStringContainsString('c=10', $out);
        $this->assertStringContainsString('r=5', $out);
    }

    public function testExplicitHeightOverridesComputed(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 10, 7);

        $this->assertStringContainsString('c=10', $out);
        $this->assertStringContainsString('r=7', $out);
    }

    public function testZeroWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 0);
    }

    public function testNegativeWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 8, 0);
    }

    public function testChunkedTransmissionForLargeImage(): void
    {
        // 500×400 noise PNG produces > 4092 bytes of base64 data, exercising chunking.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $out    = $this->renderer->render($source, 50, 40);

        // Intermediate chunk sets m=1 (more data follows).
        $this->assertStringContainsString('m=1,', $out);
        // Final chunk sets m=0 at the end (before ST).
        $this->assertStringContainsString('m=0', $out);
    }
}
