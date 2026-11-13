<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

/**
 * The CommonMark parser is built lazily (once, on first render) rather than
 * eagerly in the constructor, so the chainable `with*()` builders — which
 * each spin up a fresh instance via copy() — no longer rebuild the whole
 * Environment + extension set on every call. These tests pin that behaviour
 * and prove the refactor is output-preserving.
 */
final class ParserCacheTest extends TestCase
{
    /** @return object|string|null the parser value, or null when uninitialised */
    private function parserValue(Renderer $r): mixed
    {
        $prop = new \ReflectionProperty(Renderer::class, 'parser');
        $prop->setAccessible(true);
        return $prop->isInitialized($r) ? $prop->getValue($r) : null;
    }

    public function testParserNotBuiltByBuilderChaining(): void
    {
        // Several with*() hops, no render yet: the parser must stay null so
        // that builder chaining does not pay the Environment-assembly cost.
        $r = (new Renderer(Theme::plain()))
            ->withEmoji(true)
            ->withWordWrap(40)
            ->withHyperlinks(false)
            ->withTableWrap(true)
            ->withTheme(Theme::dracula());
        $this->assertNull(
            $this->parserValue($r),
            'parser must remain unbuilt across with*() chaining',
        );

        // The first render lazily constructs it.
        $r->render('# hi');
        $this->assertNotNull(
            $this->parserValue($r),
            'parser must be built on first render',
        );
    }

    public function testParserBuiltOnceAndReusedPerInstance(): void
    {
        $r = new Renderer(Theme::plain());
        $r->render('first render');
        $first = $this->parserValue($r);
        $r->render('second render');
        $second = $this->parserValue($r);

        $this->assertNotNull($first);
        $this->assertSame(
            $first,
            $second,
            'the parser is assembled once and reused for later renders on the same instance',
        );
    }

    public function testLazyParserPreservesOutputAcrossBuilderChaining(): void
    {
        // A representative document exercising most node kinds. A Renderer
        // reached via a with*() chain must render byte-for-byte identically to
        // one constructed directly with the same effective config — proving
        // the lazy-parser refactor did not alter output.
        $md = "# Title\n\nA **bold** paragraph with `code`, a "
            . "[link](https://example.com/x), an ![img](p.png), and text.\n\n"
            . "> a blockquote\n\n- one\n- two\n  - nested\n\n"
            . "| a | b |\n|---|---|\n| 1 | 2 |\n\n"
            . "```php\necho 'hi';\n```\n\n---\n\nDone.\n";

        $direct  = (new Renderer(Theme::dracula()))->render($md);
        $chained = (new Renderer(Theme::plain()))
            ->withTheme(Theme::dracula())
            ->withHyperlinks(true)
            ->render($md);

        $this->assertSame($direct, $chained);
        $this->assertNotSame('', $direct);
    }

    public function testDefaultThemeIsMemoised(): void
    {
        // SM-S53: the stock factories hand back one shared readonly instance,
        // so per-frame callers stop rebuilding ~23 Style/Color objects each
        // call. Two no-arg renderMarkdown() calls resolve to the same Theme.
        $this->assertSame(Theme::ansi(), Theme::ansi());
        $this->assertTrue(Theme::isStock(Theme::ansi()));
    }

    public function testRenderMarkdownReusesTheStockBundle(): void
    {
        // The stock render bundle (Renderer + its lazily-built parser) is
        // cached per stock theme, so repeated renderMarkdown() calls share
        // one parsed-environment object rather than rebuilding 3×.
        $prop = new \ReflectionProperty(Renderer::class, 'stockRenderers');
        $prop->setAccessible(true);

        Renderer::renderMarkdown('# one', Theme::dark());
        $after1 = $prop->getValue();
        Renderer::renderMarkdown('# two', Theme::dark());
        $after2 = $prop->getValue();

        $this->assertSame($after1, $after2, 'the dark bundle must be reused, not rebuilt');

        $held = $after2[spl_object_id(Theme::dark())] ?? null;
        $this->assertInstanceOf(Renderer::class, $held);

        // The parser object is the identity that proves the Environment
        // was assembled once and survived the second call.
        $parserProp = new \ReflectionProperty(Renderer::class, 'parser');
        $parserProp->setAccessible(true);
        $this->assertNotNull($parserProp->getValue($held));
    }

    public function testCustomThemeDoesNotPolluteTheStockCache(): void
    {
        $prop = new \ReflectionProperty(Renderer::class, 'stockRenderers');
        $prop->setAccessible(true);
        $before = $prop->getValue();

        $custom = Theme::dark()->with('code', Style::new()->bold());
        Renderer::renderMarkdown('# custom', $custom);

        $this->assertFalse(Theme::isStock($custom));
        $this->assertSame($before, $prop->getValue(), 'a custom theme must not enter the stock cache');
    }

    public function testDefaultRenderMarkdownOutputByteIdenticalToFresh(): void
    {
        // SM-S53 must not alter output: the memoised default path renders
        // byte-for-byte like a fresh per-call Renderer.
        $md = "# Hi\n\ntext with a [link](https://e.com) and `code`\n";
        $this->assertSame(
            (new Renderer(Theme::ansi()))->render($md),
            Renderer::renderMarkdown($md),
            'the default memoised path must match a fresh instance byte-for-byte',
        );
    }
}
