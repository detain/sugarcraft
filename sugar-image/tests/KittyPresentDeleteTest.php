<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\KittyRenderer;

/**
 * Pins the exact wire bytes of KittyRenderer::present(): the frame is drawn,
 * then the previous image is retired with the uppercase `d=I` delete.
 *
 * The earlier tests compared present() against delete() on both sides, so a
 * wrong delete form was pinned consistently and never failed. These assert
 * the literal bytes.
 */
final class KittyPresentDeleteTest extends TestCase
{
    public function testPresentRetiresPreviousImageWithFreeingDelete(): void
    {
        $renderer = new KittyRenderer();
        $src      = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $out = $renderer->present($src, 4, 2, '2', '1');

        $this->assertSame(
            $renderer->renderFrame($src, 4, 2, 2) . Ansi::APC . 'G' . 'a=d,d=I,i=1' . Ansi::ST,
            $out,
        );
    }

    public function testPresentNeverEmitsLowercaseOrUnkeyedDelete(): void
    {
        // Bare `a=d` (no d key) erases every visible placement, including the
        // frame just drawn. Lowercase `d=i` keeps the image data, so the
        // terminal's store grows by one image per frame.
        $renderer = new KittyRenderer();
        $src      = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $out = $renderer->present($src, 4, 2, '2', '1');

        $this->assertStringNotContainsString('a=d,i=', $out);
        $this->assertStringNotContainsString('a=d,d=i,', $out);
    }

    public function testPresentDoesNotRetireTheFreshlyDrawnId(): void
    {
        $renderer = new KittyRenderer();
        $src      = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $out = $renderer->present($src, 4, 2, '2', '1');

        $this->assertStringNotContainsString('a=d,d=I,i=2', $out);
    }

    public function testStandaloneDeleteFreesImageData(): void
    {
        $this->assertSame(
            Ansi::APC . 'G' . 'a=d,d=I,i=7' . Ansi::ST,
            (new KittyRenderer())->delete('7'),
        );
    }
}
