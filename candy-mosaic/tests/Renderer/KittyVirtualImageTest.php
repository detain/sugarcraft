<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\KittyOptions;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

final class KittyVirtualImageTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testUseVirtualEmitsAPlaceAction(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('a=p', $out);
    }

    public function testUseVirtualWithoutImageIdOmitsI(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit()->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // a=p must be present
        $this->assertStringContainsString('a=p', $out);
        // But i= should not appear when imageId is 0
        $this->assertStringNotContainsString('i=', $out);
    }

    public function testUseVirtualWithZIndexStillEmitsAPlaceAction(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(5)->withUseVirtual(true)->withZIndex(3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=5', $out);
        $this->assertStringContainsString('z=3', $out);
    }

    public function testUseVirtualFalseDoesNotEmitAPlaceAction(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('a=T', $out);
        $this->assertStringNotContainsString('a=p', $out);
    }

    public function testUseVirtualTransmitStillSendsPayload(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Even with useVirtual, transmit sends actual image data
        $pngBytes = $image->bytes;
        $b64 = base64_encode($pngBytes);
        $this->assertStringContainsString($b64, $out);
    }
}
