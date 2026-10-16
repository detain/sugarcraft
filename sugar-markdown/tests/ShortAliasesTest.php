<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * Short-form alias parity for the sugar-markdown `Renderer`.
 *
 * Markdown rendering is deterministic for a given config, so each test
 * renders the same input through long-form and short-form configured
 * Renderers and asserts byte-identical output.
 */
final class ShortAliasesTest extends TestCase
{
    private const SAMPLE = "# Hi\n\nA paragraph with **bold** text.\n";

    public function testThemeAlias(): void
    {
        $long  = (new Renderer(Theme::plain()))->withTheme(Theme::ascii())->render(self::SAMPLE);
        $short = (new Renderer(Theme::plain()))->theme(Theme::ascii())->render(self::SAMPLE);
        $this->assertSame($long, $short);
    }

    public function testWordWrapAlias(): void
    {
        $long  = (new Renderer(Theme::plain()))->withWordWrap(40)->render(self::SAMPLE);
        $short = (new Renderer(Theme::plain()))->wordWrap(40)->render(self::SAMPLE);
        $this->assertSame($long, $short);
    }

    public function testEmojiAlias(): void
    {
        $md = "Hello :rocket:";
        $long  = (new Renderer(Theme::plain()))->withEmoji(true)->render($md);
        $short = (new Renderer(Theme::plain()))->emoji(true)->render($md);
        $this->assertSame($long, $short);
    }

    public function testHyperlinksAlias(): void
    {
        $md = "[link](https://example.com)";
        $long  = (new Renderer(Theme::plain()))->withHyperlinks(true)->render($md);
        $short = (new Renderer(Theme::plain()))->hyperlinks(true)->render($md);
        $this->assertSame($long, $short);
    }

    public function testTableWrapAlias(): void
    {
        $long  = (new Renderer(Theme::plain()))->withTableWrap(true)->render(self::SAMPLE);
        $short = (new Renderer(Theme::plain()))->tableWrap(true)->render(self::SAMPLE);
        $this->assertSame($long, $short);
    }
}
