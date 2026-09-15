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

        // Begins with APC G (Kitty graphics begin) + width/height params,
        // and the begin frame opens the transaction with m=1 (ANSI audit:
        // the old DCS `ESC P q` header was DECSIXEL and never activated
        // Kitty graphics).
        $this->assertStringStartsWith("\x1b_Gc=8,r=4,m=1;\x1b\\", $out);
        $this->assertStringContainsString('c=8', $out);    // cell columns
        $this->assertStringContainsString('r=4', $out);    // cell rows
    }

    public function testRendersKittyEndSequence(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        // Every Kitty frame — begin, each chunk — is a self-contained APC
        // sequence ending in ST (String Terminator).
        $this->assertStringEndsWith("\x1b\\", $out);
        // The transaction is closed by the final data chunk's m=0, exactly
        // once: no dangling open frame, no duplicate end frames.
        $this->assertSame(1, substr_count($out, 'm=0;'));
        // Frame-structural split: every `ESC _ G` segment up to ST carries
        // only key=value/; characters — no raw payload leaked outside a frame.
        $frames = explode("\x1b_G", $out);
        $this->assertSame('', array_shift($frames), 'output must start with the APC G introducer');
        foreach ($frames as $frame) {
            $this->assertStringEndsWith("\x1b\\", $frame);
            $body = substr($frame, 0, -2);
            $sep = strpos($body, ';');
            $this->assertIsInt($sep, "frame has no data separator: {$body}");
            $attrs = substr($body, 0, $sep);
            $data = substr($body, $sep + 1);
            $this->assertMatchesRegularExpression('/^[a-z]=[0-9a-z]+(,[a-z]=[0-9a-z]+)*$/', $attrs);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]*$/', $data);
        }
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

        // Intermediate chunk sets m=1 (more data follows), framed as its
        // own APC sequence with the payload between ';' and ST.
        $this->assertStringContainsString("\x1b_Gm=1;", $out);
        // Final chunk sets m=0 (closes the transaction) — last frame's
        // terminator is the very end of the output.
        $this->assertStringContainsString("\x1b_Gm=0;", $out);
    }
}
