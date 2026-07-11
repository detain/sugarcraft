<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
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
}
