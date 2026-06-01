<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use SugarCraft\Glow\GlowModel;
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

    public function testGlowModelWithStyledContentRendersAnsi(): void
    {
        // Render markdown with ANSI styles, then pass through GlowModel viewport
        $md = "# Hello\n\nThis is **bold** and *italic* text.\n\n- item 1\n- item 2";
        $styled = Renderer::renderMarkdown($md, Theme::dracula());

        $m = GlowModel::fromContent($styled, 80, 10);
        $output = $m->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/glowmodel-styled.golden',
            $output,
        );
    }

    public function testGlowModelWithPlainContentRendersCorrectly(): void
    {
        $m = GlowModel::fromContent("line 1\nline 2\nline 3", 80, 5);
        $output = $m->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/glowmodel-plain.golden',
            $output,
        );
    }
}
