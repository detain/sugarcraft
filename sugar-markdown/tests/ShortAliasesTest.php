<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Style\StyleSheet;
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

    /**
     * @return iterable<string, array{0:string, 1:string, mixed, 2:string}>
     */
    public static function remainingAliasPairProvider(): iterable
    {
        yield 'baseURL' => ['withBaseURL', 'baseURL', 'https://example.com/', "[a](rel)\n"];
        yield 'inline-table-links' => ['withInlineTableLinks', 'inlineTableLinks', false, "| a | b |\n|---|---|\n| [x](https://e.com) | y |\n"];
        yield 'table-column-budget' => ['withTableColumnBudget', 'tableColumnBudget', true, "| alpha | beta |\n|-------|------|\n| one   | two  |\n"];
        yield 'fence-column-budget' => ['withFenceColumnBudget', 'fenceColumnBudget', true, "```\nlong line of code that would overflow a narrow pane\n```\n"];
        yield 'preserved-new-lines' => ['withPreservedNewLines', 'preservedNewLines', true, "one\n\ntwo\n\n\nthree\n"];
        yield 'standard-style' => ['withStandardStyle', 'standardStyle', 'dracula', self::SAMPLE];
        yield 'sanitize' => ['withSanitize', 'sanitize', false, "hi\x1b[31mred\x1b[0m\n"];
        yield 'raw-control-chars' => ['withRawControlChars', 'rawControlChars', true, "hi\x1b[31mred\x1b[0m\n"];
        yield 'style-sheet' => ['withStyleSheet', 'styleSheet', StyleSheet::base(), self::SAMPLE];
    }

    /**
     * @dataProvider remainingAliasPairProvider
     */
    public function testTheRemainingAliasesMatchTheirLongForms(string $long, string $short, mixed $arg, string $md): void
    {
        // The alias must thread the exact same value into mutate(); a dropped
        // or re-typed argument diverges here on rendered bytes.
        $longOut  = (new Renderer(Theme::plain()))->{$long}($arg)->withWordWrap(30)->render($md);
        $shortOut = (new Renderer(Theme::plain()))->{$short}($arg)->withWordWrap(30)->render($md);
        $this->assertSame($longOut, $shortOut, "alias {$short}() diverges from {$long}()");
    }
}
