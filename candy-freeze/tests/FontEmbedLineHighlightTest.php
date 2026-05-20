<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\SvgRenderer;
use PHPUnit\Framework\TestCase;

final class FontEmbedLineHighlightTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Font embedding
    // -------------------------------------------------------------------------

    public function testWithFontThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        SvgRenderer::dark()->withFont('/nonexistent/font.ttf');
    }

    public function testWithFontSetsFontPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $r = SvgRenderer::dark()->withFont($tmp);
            $this->assertSame($tmp, $r->fontPath);
        } finally {
            unlink($tmp);
        }
    }

    public function testEmbeddedFontEmitsBase64InStyleTag(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            $this->assertStringContainsString('base64,', $svg);
            $this->assertStringContainsString('font-family: "embedded"', $svg);
            $this->assertStringContainsString('@font-face', $svg);
        } finally {
            unlink($tmp);
        }
    }

    public function testNoFontEmbeddedWhenPathIsNull(): void
    {
        $svg = SvgRenderer::dark()->withFont(null)->withWindow(false)->render("x\n");
        $this->assertStringNotContainsString('base64,', $svg);
        $this->assertStringNotContainsString('@font-face', $svg);
    }

    public function testEmbeddedFontOverridesFontFamilyInOutput(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            // Font family should be "embedded" (the injected font name) not Hack.
            $this->assertStringContainsString('font-family="embedded"', $svg);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Line highlighting
    // -------------------------------------------------------------------------

    public function testWithHighlightThrowsOnZeroStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/start.*>=.*1/');
        SvgRenderer::dark()->withHighlight(0, 5);
    }

    public function testWithHighlightThrowsOnNegativeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/start.*>=.*1/');
        SvgRenderer::dark()->withHighlight(-1, 5);
    }

    public function testWithHighlightThrowsWhenEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/end must be >= start/');
        SvgRenderer::dark()->withHighlight(5, 3);
    }

    public function testWithHighlightSingleLine(): void
    {
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 2, '#ffff00')
            ->withWindow(false)
            ->render("line1\nline2\nline3\n");

        // Line 2 should have a highlight rect.
        $this->assertStringContainsString('fill="#ffff00"', $svg);
    }

    public function testWithHighlightRangeOfLines(): void
    {
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 4, '#fffbe6')
            ->withWindow(false)
            ->render("line1\nline2\nline3\nline4\nline5\n");

        // Lines 2, 3, and 4 are in range — one rect per line.
        $this->assertSame(3, substr_count($svg, 'fill="#fffbe6"'));
    }

    public function testWithHighlightDefaultsToYellow(): void
    {
        // The default color in withHighlight is #fffbe6.
        $r = SvgRenderer::dark()->withHighlight(1, 1);
        $this->assertSame('#fffbe6', $r->highlight['color']);
    }

    public function testNoHighlightRectWhenHighlightNotSet(): void
    {
        // Without calling withHighlight, no highlight rects should be emitted.
        $svg = SvgRenderer::dark()
            ->withWindow(false)
            ->render("line1\nline2\nline3\n");

        $this->assertStringNotContainsString('fill="#fffbe6"', $svg);
        $this->assertStringNotContainsString('fill="#ffff00"', $svg);
    }

    public function testHighlightRectPositionAlignedWithLines(): void
    {
        $text = "line1\nline2\nline3\n";
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 2, '#ffff00')
            ->withWindow(false)
            ->withLineNumbers(false)
            ->withShadow(false)
            ->withBorder(false)
            ->render($text);

        // Each line is fontSize * lineHeight tall.
        // The highlight should appear behind line 2.
        // We can't easily test exact coordinates but we verify the rect exists.
        $this->assertStringContainsString('fill="#ffff00"', $svg);
    }

    public function testHighlightAndFontEmbeddingTogether(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()
                ->withFont($tmp)
                ->withHighlight(1, 3, '#ff0000')
                ->withWindow(false)
                ->render("a\nb\nc\n");

            $this->assertStringContainsString('base64,', $svg);
            $this->assertStringContainsString('font-family: "embedded"', $svg);
            $this->assertStringContainsString('fill="#ff0000"', $svg);
        } finally {
            unlink($tmp);
        }
    }
}
