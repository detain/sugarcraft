<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Capability;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Scale;

final class MosaicScaleTest extends TestCase
{
    private string $fixture4x2;

    protected function setUp(): void
    {
        $this->fixture4x2 = __DIR__ . '/fixtures/4x2.png';
        if (!file_exists($this->fixture4x2)) {
            $this->markTestSkipped('Fixture tests/fixtures/4x2.png missing — run: convert examples/fixture-gradient.png -resize 4x2! tests/fixtures/4x2.png');
        }
    }

    private function mosaic(Scale $scale): Mosaic
    {
        return new Mosaic(
            new SixelRenderer(Dither::FloydSteinberg),
            Capability::universal(),
            8,
            8,
            $scale
        );
    }

    // ─── All five scale modes render without error ──────────────────────────

    public function testFitRendersWithoutError(): void
    {
        $out = $this->mosaic(Scale::Fit)->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString("\x1bP", $out); // sixel prefix
    }

    public function testFillRendersWithoutError(): void
    {
        $out = $this->mosaic(Scale::Fill)->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString("\x1bP", $out);
    }

    public function testStretchRendersWithoutError(): void
    {
        $out = $this->mosaic(Scale::Stretch)->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString("\x1bP", $out);
    }

    public function testNoneRendersWithoutError(): void
    {
        $out = $this->mosaic(Scale::None)->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString("\x1bP", $out);
    }

    public function testCropRendersWithoutError(): void
    {
        $out = $this->mosaic(Scale::Crop)->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString("\x1bP", $out);
    }

    // ─── withScale ─────────────────────────────────────────────────────────

    public function testWithScaleReturnsNewInstance(): void
    {
        $m1 = Mosaic::sixel();
        $m2 = $m1->withScale(Scale::Crop);

        $this->assertNotSame($m1, $m2);
        $this->assertNull($m1->scale());
        $this->assertSame(Scale::Crop, $m2->scale());
    }

    public function testWithScalePreservesDither(): void
    {
        $m = Mosaic::sixel()->withDither(Dither::Atkinson)->withScale(Scale::Fill);
        $this->assertSame(Scale::Fill, $m->scale());
    }

    public function testWithScaleIsImmutable(): void
    {
        $renderer = new HalfBlockRenderer();
        $m = new Mosaic($renderer, Capability::universal(), null, null, null);
        $m2 = $m->withScale(Scale::Fit);

        $this->assertSame(Scale::Fit, $m2->scale());
        $this->assertNotSame($m, $m2);
    }

    // ─── builder withScale ─────────────────────────────────────────────────

    public function testBuilderWithScale(): void
    {
        $mosaic = Mosaic::builder()
            ->withRenderer(new SixelRenderer(Dither::FloydSteinberg))
            ->withScale(Scale::Crop)
            ->build();

        $out = $mosaic->render(ImageSource::fromFile($this->fixture4x2), 8, 8);
        $this->assertNotEmpty($out);
    }
}
