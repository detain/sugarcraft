<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;

final class ChafaRendererTest extends TestCase
{
    public function testChafaRendererName(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertSame('chafa', $renderer->name());
    }

    public function testChafaRendererSupportsAlpha(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertTrue($renderer->supportsAlpha());
    }

    public function testChafaRendererConstructorOptions(): void
    {
        $renderer = new ChafaRenderer(['--colors=16', '--work=n']);
        $this->assertSame('chafa', $renderer->name());
        $this->assertTrue($renderer->supportsAlpha());
    }

    public function testChafaRendererDefaultOptions(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertSame('chafa', $renderer->name());
    }

    public function testChafaRendererWith1x1GifPixel(): void
    {
        // 1x1 red GIF pixel
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);

        $renderer = new ChafaRenderer(['--colors=256']);

        // If chafa is not installed, this will throw a RuntimeException
        try {
            $output = $renderer->render($source, 10, 10);
            $this->assertIsString($output);
        } catch (\RuntimeException $e) {
            // Expected if chafa is not installed
            $this->assertStringContainsString('chafa', strtolower($e->getMessage()));
        }
    }

    public function testChafaRendererInvalidWidthThrows(): void
    {
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);
        $renderer = new ChafaRenderer();

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($source, 0);
    }

    public function testChafaRendererInvalidHeightThrows(): void
    {
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);
        $renderer = new ChafaRenderer();

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($source, 10, -1);
    }
}
