<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testRenderMarkdownBasicStyled(): void
    {
        $md = "# Hello\n\nThis is **bold** text.\n\n- item 1\n- item 2";
        $output = Renderer::renderMarkdown($md, Theme::dracula());

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Hello', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/render-markdown-bold.golden',
            $output,
        );
    }

    public function testRenderMarkdownWithCodeBlock(): void
    {
        $md = "```php\necho 'hello';\n```";
        $output = Renderer::renderMarkdown($md, Theme::tokyoNight());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/render-markdown-code.golden',
            $output,
        );
    }

    public function testRenderMarkdownPlainTheme(): void
    {
        $md = "# Title\n\nSome **bold** and *italic* text.\n\n> A blockquote";
        $output = Renderer::renderMarkdown($md, Theme::plain());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/render-markdown-plain.golden',
            $output,
        );
    }
}
