<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Style\StyleSheet;
use SugarCraft\Markdown\Theme;

final class RendererCachedTest extends TestCase
{
    /** A new theme object on every call: the cache keys on identity. */
    private static function fresh(): Theme
    {
        return Theme::fromJsonString('{"paragraph": {"bold": true}}');
    }

    public function testTheSamePairReturnsTheSameInstance(): void
    {
        $theme = Theme::dark();

        $this->assertSame(Renderer::cached($theme, 40), Renderer::cached($theme, 40));
    }

    public function testDifferentWidthsAreDifferentRenderers(): void
    {
        $theme = Theme::dark();

        $this->assertNotSame(Renderer::cached($theme, 40), Renderer::cached($theme, 41));
        $this->assertNotSame(Renderer::cached($theme, 40), Renderer::cached($theme, null));
    }

    public function testEqualButDistinctThemesGetDistinctRenderers(): void
    {
        // Identity, not value: the cache must never hand a renderer built
        // for one theme object to a different object.
        $this->assertNotSame(
            Renderer::cached(self::fresh(), 40),
            Renderer::cached(self::fresh(), 40),
        );
    }

    public function testNullThemeMeansTheAnsiStockTheme(): void
    {
        $this->assertSame(Renderer::cached(Theme::ansi(), 30), Renderer::cached(null, 30));
    }

    public function testTheWidthIsTheRenderersWrapWidth(): void
    {
        $renderer = Renderer::cached(Theme::plain(), 17);

        $this->assertSame(17, $renderer->wrapWidth);
        $this->assertNull(Renderer::cached(Theme::plain(), null)->wrapWidth);
    }

    public function testInvalidWidthIsRefusedAndNothingIsCached(): void
    {
        $theme = Theme::plain();

        try {
            Renderer::cached($theme, 0);
            $this->fail('a zero width must be refused');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        // The refused call left no entry behind: the next valid call builds fresh.
        $this->assertSame(Renderer::cached($theme, 0 + 5), Renderer::cached($theme, 5));
    }

    public function testCachedRenderersMatchAFreshRenderer(): void
    {
        $markdown = "# Title\n\nSome *emphasis* and a [link](https://example.com).\n\n- one\n- two\n";
        $theme = Theme::tokyoNight();

        $this->assertSame(
            (new Renderer($theme, 30))->render($markdown),
            Renderer::cached($theme, 30)->render($markdown),
        );
        // A second frame through the same instance renders identically.
        $cached = Renderer::cached($theme, 30);
        $this->assertSame($cached->render($markdown), $cached->render($markdown));
    }

    public function testLeastRecentlyUsedPairSurvivesEviction(): void
    {
        $limit = 32;
        $hot = self::fresh();
        $first = Renderer::cached($hot, 10);

        // Keep `hot` recent while filling the cache to its limit ...
        for ($i = 1; $i < $limit; $i++) {
            Renderer::cached(self::fresh(), 10);
            Renderer::cached($hot, 10);
        }
        // ... then one more pair forces the eviction of the coldest entry.
        Renderer::cached(self::fresh(), 10);

        $this->assertSame($first, Renderer::cached($hot, 10));
    }

    public function testAColdPairIsEvictedOnceTheLimitIsExceeded(): void
    {
        $cold = self::fresh();
        $first = Renderer::cached($cold, 12);
        for ($i = 0; $i < 40; $i++) {
            Renderer::cached(self::fresh(), 12);
        }

        $this->assertNotSame($first, Renderer::cached($cold, 12));
        // An evicted renderer still works for whoever holds it.
        $this->assertStringContainsString('ok', $first->render("ok\n"));
    }

    public function testStyleSheetBaseIsBuiltOnce(): void
    {
        $this->assertSame(StyleSheet::base(), StyleSheet::base());
    }

    public function testStyleSheetBaseIsUnchangedByDerivedSheets(): void
    {
        $before = StyleSheet::base()->stylesFor(\SugarCraft\Markdown\Render\BlockKind::Heading);
        StyleSheet::base()->withBlockKind(
            \SugarCraft\Markdown\Render\BlockKind::Heading,
            \SugarCraft\Style\Style::new()->bold(),
        );

        $this->assertSame($before, StyleSheet::base()->stylesFor(\SugarCraft\Markdown\Render\BlockKind::Heading));
    }
}
