<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

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
}
