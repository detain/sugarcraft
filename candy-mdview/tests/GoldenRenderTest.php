<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\MdView\FrontMatter;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
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

    public function testMdViewModelWithStyledContentRendersAnsi(): void
    {
        // Render markdown with ANSI styles, then pass through MdViewModel viewport
        $md = "# Hello\n\nThis is **bold** and *italic* text.\n\n- item 1\n- item 2";
        $styled = Renderer::renderMarkdown($md, Theme::dracula());

        $m = MdViewModel::fromContent($styled, 80, 10);
        $output = $m->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mdviewmodel-styled.golden',
            $output,
        );
    }

    public function testMdViewModelWithPlainContentRendersCorrectly(): void
    {
        $m = MdViewModel::fromContent("line 1\nline 2\nline 3", 80, 5);
        $output = $m->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mdviewmodel-plain.golden',
            $output,
        );
    }

    /**
     * Finding 007: with the front-matter rule applied, a metadata-fenced
     * document byte-matches the render of its body alone — the fence is
     * invisible to the renderer, not merely "hidden" at the display layer.
     */
    public function testFrontMatterDocumentRendersByteIdenticalToItsBody(): void
    {
        $fm    = "---\ntitle: My Doc\ntags: [a, b]\n---\n\n# Head\n\nThis is **bold** text.\n";
        $plain = "\n# Head\n\nThis is **bold** text.\n";

        self::assertSame($plain, FrontMatter::strip($fm));

        $styled = Renderer::renderMarkdown(FrontMatter::strip($fm), Theme::dracula());
        self::assertSame(
            Renderer::renderMarkdown($plain, Theme::dracula()),
            $styled,
            'strip must be a no-op for the renderer beyond removing the block',
        );

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/front-matter-stripped.golden',
            MdViewModel::fromContent($styled, 80, 10)->view(),
        );
    }
}
