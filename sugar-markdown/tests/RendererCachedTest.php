<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Style\StyleSheet;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;

/**
 * Renderer::cached() hands one bundle (parser, Environment, stylesheet) to
 * every caller of a stock theme at a width, and StyleSheet::base() is built
 * once for the process (audit rank 39).
 */
final class RendererCachedTest extends TestCase
{
    public function testStockThemeAtOneWidthIsOneSharedRenderer(): void
    {
        $this->assertSame(
            Renderer::cached(Theme::dark(), 40),
            Renderer::cached(Theme::dark(), 40),
        );
    }

    public function testWidthAndThemeEachMakeADistinctRenderer(): void
    {
        $this->assertNotSame(Renderer::cached(Theme::dark(), 40), Renderer::cached(Theme::dark(), 41));
        $this->assertNotSame(Renderer::cached(Theme::dark(), 40), Renderer::cached(Theme::light(), 40));
        $this->assertNotSame(Renderer::cached(Theme::dark(), null), Renderer::cached(Theme::dark(), 40));
    }

    public function testCachedRendererCarriesItsWidthAndTheme(): void
    {
        $r = Renderer::cached(Theme::pink(), 72);

        $this->assertSame(72, $r->wrapWidth);
        $this->assertSame(Theme::pink(), $r->theme);
    }

    public function testCustomThemeIsNeverMemoized(): void
    {
        $custom = Theme::dark()->with('code', Style::new()->bold());

        $this->assertNotSame(Renderer::cached($custom, 40), Renderer::cached($custom, 40));
    }

    public function testRefusedWidthThrowsAndIsNotCached(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Renderer::cached(Theme::dark(), 0);
    }

    public function testReusedRendererMatchesAFreshOneAcrossDocuments(): void
    {
        $docs = ["# One\n\nAlpha **bold**.", "## Two\n\n- a\n- b\n", "Plain paragraph that is long enough to wrap at the width."];
        $cached = Renderer::cached(Theme::dark(), 24);

        foreach ([...$docs, ...$docs] as $doc) {
            $this->assertSame((new Renderer(Theme::dark(), 24))->render($doc), $cached->render($doc));
        }
    }

    public function testStyleSheetBaseIsBuiltOnce(): void
    {
        $this->assertSame(StyleSheet::base(), StyleSheet::base());
    }

    public function testCacheStaysBounded(): void
    {
        $prop = new \ReflectionProperty(Renderer::class, 'cachedRenderers');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        for ($width = 1; $width <= 80; $width++) {
            Renderer::cached(Theme::dark(), $width);
        }

        $this->assertLessThanOrEqual(32, \count($prop->getValue()));
        // The oldest width left first; the newest is still held.
        $this->assertArrayHasKey(spl_object_id(Theme::dark()) . '|80', $prop->getValue());
        $this->assertArrayNotHasKey(spl_object_id(Theme::dark()) . '|1', $prop->getValue());
    }
}
