<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\CellGeometry;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Passthrough\ScreenPassthrough;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::renderRgb
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::present
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::supportsIdDelete
 * @covers \SugarCraft\Image\Renderer\Iterm2Renderer::renderRgb
 * @covers \SugarCraft\Image\Renderer\Iterm2Renderer::present
 * @covers \SugarCraft\Image\Renderer\Iterm2Renderer::supportsIdDelete
 * @covers \SugarCraft\Image\Renderer\SixelRenderer::renderRgb
 * @covers \SugarCraft\Image\Renderer\SixelRenderer::present
 * @covers \SugarCraft\Image\Renderer\SixelRenderer::supportsIdDelete
 * @covers \SugarCraft\Image\Renderer\HalfBlockRenderer::renderRgb
 * @covers \SugarCraft\Image\Renderer\HalfBlockRenderer::present
 * @covers \SugarCraft\Image\Renderer\HalfBlockRenderer::supportsIdDelete
 * @covers \SugarCraft\Image\Concerns\ImageSourceRgbFallback
 * @covers \SugarCraft\Image\TmuxPassthroughDecorator
 * @covers \SugarCraft\Image\Passthrough\ScreenPassthrough
 */
final class RenderRgbTest extends TestCase
{
    private const W = 8;
    private const H = 6;

    /** A deterministic 8x6 RGBA8 buffer with varied pixels. */
    private function rgba(): string
    {
        $out = '';
        for ($i = 0; $i < self::W * self::H; $i++) {
            $out .= chr(($i * 37) % 256) . chr(($i * 11) % 256) . chr(($i * 73) % 256) . chr(255);
        }

        return $out;
    }

    private function source(string $rgba): ImageSource
    {
        return ImageSource::fromRgb($rgba, self::W, self::H, true);
    }

    public function testKittyRenderRgbMatchesTheRawUncompressedPath(): void
    {
        $rgba = $this->rgba();
        $kitty = new KittyRenderer();

        $this->assertSame(
            $kitty->renderUncompressed($this->source($rgba), 4, null, $rgba),
            $kitty->renderRgb($rgba, self::W, self::H, 4),
        );
    }

    public function testKittyRenderRgbSendsRawRgbaNotPng(): void
    {
        $out = (new KittyRenderer())->renderRgb($this->rgba(), self::W, self::H, 4);

        $this->assertStringContainsString('f=32', $out);
        $this->assertStringContainsString('s=' . self::W, $out);
        $this->assertStringContainsString('v=' . self::H, $out);
        $this->assertStringNotContainsString('f=100', $out, 'no PNG container on the raw path');
    }

    public function testKittyRenderRgbAutoHeightFollowsCellGeometry(): void
    {
        $rgba = $this->rgba();
        $kitty = new KittyRenderer();
        $expected = CellGeometry::heightCells(4, self::W / self::H);

        $this->assertSame(
            $kitty->renderRgb($rgba, self::W, self::H, 4, $expected),
            $kitty->renderRgb($rgba, self::W, self::H, 4),
        );
    }

    public function testKittyRenderRgbRejectsAMismatchedBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->renderRgb(str_repeat("\x00", 10), self::W, self::H, 4);
    }

    public function testKittyRenderRgbRejectsZeroPixelDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->renderRgb('', 0, 0, 4);
    }

    public function testKittyRenderRgbRejectsZeroCellWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->renderRgb($this->rgba(), self::W, self::H, 0);
    }

    public function testKittyPresentRetiresThePreviousIdAfterTheNewFrame(): void
    {
        $kitty = new KittyRenderer();
        $src = $this->source($this->rgba());

        $this->assertSame(
            $kitty->renderFrame($src, 4, null, 2) . $kitty->delete('1'),
            $kitty->present($src, 4, null, '2', '1'),
        );
    }

    public function testKittyPresentWithoutAPreviousIdOnlyDraws(): void
    {
        $kitty = new KittyRenderer();
        $src = $this->source($this->rgba());

        $this->assertSame($kitty->renderFrame($src, 4, null, 1), $kitty->present($src, 4, null, '1', null));
    }

    public function testKittyPresentNeverDeletesTheFrameItJustDrew(): void
    {
        $kitty = new KittyRenderer();
        $src = $this->source($this->rgba());

        $this->assertSame($kitty->renderFrame($src, 4, null, 1), $kitty->present($src, 4, null, '1', '1'));
    }

    /** @return iterable<string,array{string}> */
    public static function badIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'word' => ['abc'];
        yield 'empty' => [''];
    }

    /** @dataProvider badIds */
    public function testKittyPresentRejectsANonPositiveOrNonNumericId(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->present($this->source($this->rgba()), 4, null, $id, null);
    }

    public function testKittySupportsIdDelete(): void
    {
        $this->assertTrue((new KittyRenderer())->supportsIdDelete());
    }

    public function testIterm2RenderRgbMatchesTheTiffPath(): void
    {
        $rgba = $this->rgba();
        $iterm = new Iterm2Renderer();

        $this->assertSame(
            $iterm->renderTiff($this->source($rgba), 4, null, $rgba),
            $iterm->renderRgb($rgba, self::W, self::H, 4),
        );
    }

    public function testIterm2RenderRgbRejectsAMismatchedBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Iterm2Renderer())->renderRgb('short', self::W, self::H, 4);
    }

    public function testIterm2PresentPopsThePreviousFrameBeforeDrawing(): void
    {
        // OSC 1337;Pop removes the topmost image, so the old frame goes first.
        $iterm = new Iterm2Renderer();
        $src = $this->source($this->rgba());

        $this->assertSame(
            $iterm->delete('1') . $iterm->render($src, 4, null),
            $iterm->present($src, 4, null, '2', '1'),
        );
    }

    public function testIterm2PresentWithoutAPreviousIdOnlyDraws(): void
    {
        $iterm = new Iterm2Renderer();
        $src = $this->source($this->rgba());

        $this->assertSame($iterm->render($src, 4, null), $iterm->present($src, 4, null, '1', null));
    }

    public function testIterm2DoesNotSupportIdDelete(): void
    {
        $this->assertFalse((new Iterm2Renderer())->supportsIdDelete());
    }

    public function testSixelRenderRgbMatchesRenderOfTheSameSource(): void
    {
        $rgba = $this->rgba();
        $sixel = new SixelRenderer();

        $this->assertSame($sixel->render($this->source($rgba), 4, null), $sixel->renderRgb($rgba, self::W, self::H, 4));
    }

    public function testSixelPresentIsARenderWithNothingToDelete(): void
    {
        $sixel = new SixelRenderer();
        $src = $this->source($this->rgba());

        $this->assertSame($sixel->render($src, 4, null), $sixel->present($src, 4, null, '1', '2'));
    }

    public function testSixelDoesNotSupportIdDelete(): void
    {
        $this->assertFalse((new SixelRenderer())->supportsIdDelete());
    }

    public function testTextTierRenderRgbMatchesRenderOfTheSameSource(): void
    {
        $rgba = $this->rgba();
        $text = new HalfBlockRenderer();

        $this->assertSame($text->render($this->source($rgba), 4, null), $text->renderRgb($rgba, self::W, self::H, 4));
    }

    public function testTextTierPresentIsARenderAndDoesNotDelete(): void
    {
        $text = new HalfBlockRenderer();
        $src = $this->source($this->rgba());

        $this->assertSame($text->render($src, 4, null), $text->present($src, 4, null, '1', '2'));
        $this->assertFalse($text->supportsIdDelete());
    }

    public function testTmuxDecoratorWrapsTheInnerRgbAndPresentBytes(): void
    {
        $rgba = $this->rgba();
        $src = $this->source($rgba);
        $inner = new KittyRenderer();
        $tmux = new TmuxPassthroughDecorator($inner);

        $this->assertSame($tmux->wrap($inner->renderRgb($rgba, self::W, self::H, 4)), $tmux->renderRgb($rgba, self::W, self::H, 4));
        $this->assertSame($tmux->wrap($inner->present($src, 4, null, '2', '1')), $tmux->present($src, 4, null, '2', '1'));
    }

    public function testTmuxDecoratorForwardsSupportsIdDelete(): void
    {
        $this->assertTrue((new TmuxPassthroughDecorator(new KittyRenderer()))->supportsIdDelete());
        $this->assertFalse((new TmuxPassthroughDecorator(new SixelRenderer()))->supportsIdDelete());
    }

    public function testScreenPassthroughWrapsTheInnerRgbAndPresentBytes(): void
    {
        $rgba = $this->rgba();
        $src = $this->source($rgba);
        $inner = new KittyRenderer();
        $screen = new ScreenPassthrough($inner);

        $this->assertSame($screen->wrap($inner->renderRgb($rgba, self::W, self::H, 4)), $screen->renderRgb($rgba, self::W, self::H, 4));
        $this->assertSame($screen->wrap($inner->present($src, 4, null, '2', '1')), $screen->present($src, 4, null, '2', '1'));
    }

    public function testScreenPassthroughForwardsSupportsIdDelete(): void
    {
        $this->assertTrue((new ScreenPassthrough(new KittyRenderer()))->supportsIdDelete());
        $this->assertFalse((new ScreenPassthrough(new Iterm2Renderer()))->supportsIdDelete());
    }
}
