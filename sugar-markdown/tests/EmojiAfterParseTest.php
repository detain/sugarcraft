<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * M1 pins: emoji shortcodes expand AFTER parsing, in text nodes only.
 * Code spans, fenced code and link destinations keep the source bytes —
 * the pre-parse rewrite corrupted exactly those (a `:smile:` inside a PHP
 * fence became a glyph; a destination became unresolvable). Escaped
 * shortcodes (\:smile\:) now expand, because CommonMark hands the text
 * node the literal ':smile:' — the backslash only protects the source
 * from being markup, it cannot hide the token from a text-node rewrite
 * (disclosed behavior change, matching glamour).
 */
final class EmojiAfterParseTest extends TestCase
{
    public function testFencedCodeKeepsShortcodesLiteral(): void
    {
        $out = Renderer::plain()->withEmoji(true)->render("```php\n:fire:\n```\n");
        self::assertStringContainsString(':fire:', $out);
        self::assertStringNotContainsString("\u{1F525}", $out);
    }

    public function testCodeSpansKeepShortcodesLiteral(): void
    {
        $out = Renderer::plain()->withEmoji(true)->render('Ship `:rocket:` it');
        self::assertStringContainsString(':rocket:', $out);
        self::assertStringNotContainsString("\u{1F680}", $out);
    }

    public function testLinkDestinationsAreNeverRewritten(): void
    {
        $out = (new Renderer())->withEmoji(true)->render('[x](https://ex.com/:smile:)');
        self::assertStringContainsString("\x1b]8;;https://ex.com/:smile:\x1b\\", $out);
        self::assertStringNotContainsString("\u{1F604}", $out);

        $plain = Renderer::plain()->withEmoji(true)->render('[x](https://ex.com/:smile:)');
        self::assertStringContainsString(' (https://ex.com/:smile:)', $plain);
    }

    public function testBackslashEscapedShortcodeExpandsInText(): void
    {
        // Disclosed change: pre-parse, the backslashes blocked the pattern and
        // the literal ':smile:' survived corrupted-by-stripping. Post-parse,
        // the text node carries ':smile:' and expansion applies.
        self::assertSame("\u{1F604} hello", Renderer::plain()->withEmoji(true)->render('\\:smile\\: hello'));
        self::assertSame(':smile: hello', Renderer::plain()->render('\\:smile\\: hello'));
    }

    public function testPlainTextStillExpands(): void
    {
        $out = Renderer::plain()->withEmoji(true)->render('Hello :rocket: world');
        self::assertStringContainsString("\u{1F680}", $out);
    }

    /**
     * The substitution is byte-local to a text run, so the stream channel
     * and the whole-document channel must agree byte-for-byte even with
     * emoji on and even when chunks split mid-shortcode.
     */
    public function testStreamAndRenderStayIdenticalWithEmojiEnabled(): void
    {
        $doc = "Ship `:rocket:` it :tada:\n\n```\n:fire:\n```\n\n[x](https://ex.com/:smile:)\n";
        $renderer = Renderer::plain()->withEmoji(true);

        $bytes = '';
        $chunks = [];
        for ($i = 0; $i < strlen($doc); $i += 7) {
            $chunks[] = substr($doc, $i, 7);
        }
        foreach ($renderer->stream($chunks) as $chunk) {
            $bytes .= $chunk;
        }
        self::assertSame($renderer->render($doc), $bytes);
        self::assertStringContainsString("\u{1F389}", $bytes);
        self::assertStringContainsString(':rocket:', $bytes);
    }
}
