<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\SvgRenderer;
use SugarCraft\Freeze\Theme;
use PHPUnit\Framework\TestCase;

final class SvgRendererTest extends TestCase
{
    public function testEmitsSvgHeaderAndRoot(): void
    {
        $svg = SvgRenderer::dark()->render("hello world\n");
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
    }

    public function testRendersTextContent(): void
    {
        $svg = SvgRenderer::dark()->render("hello\nworld");
        $this->assertStringContainsString('hello', $svg);
        $this->assertStringContainsString('world', $svg);
    }

    public function testWindowControlsRenderedByDefault(): void
    {
        $svg = SvgRenderer::dark()->render('x');
        // Three traffic-light circles.
        $this->assertSame(3, substr_count($svg, '<circle'));
    }

    public function testWithoutWindow(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('x');
        $this->assertSame(0, substr_count($svg, '<circle'));
    }

    public function testWithoutShadowOmitsFilter(): void
    {
        $svg = SvgRenderer::dark()->withShadow(false)->render('x');
        $this->assertStringNotContainsString('<filter', $svg);
        $this->assertStringNotContainsString('feDropShadow', $svg);
    }

    public function testLineNumbersAddGutter(): void
    {
        $svg = SvgRenderer::dark()->withLineNumbers(true)->render("a\nb\nc");
        // Each line number rendered as text with the lineNumber colour.
        $this->assertStringContainsString('1', $svg);
        $this->assertStringContainsString('2', $svg);
        $this->assertStringContainsString('3', $svg);
    }

    public function testThemePresetsAppearInOutput(): void
    {
        // Each preset produces a different background colour in the frame.
        $bgs = [
            SvgRenderer::dark()->theme->background       => SvgRenderer::dark(),
            SvgRenderer::light()->theme->background      => SvgRenderer::light(),
            SvgRenderer::dracula()->theme->background    => SvgRenderer::dracula(),
            SvgRenderer::tokyoNight()->theme->background => SvgRenderer::tokyoNight(),
            SvgRenderer::nord()->theme->background       => SvgRenderer::nord(),
        ];
        $this->assertCount(5, $bgs);
        foreach ($bgs as $bg => $renderer) {
            $svg = $renderer->render('x');
            $this->assertStringContainsString('fill="' . $bg . '"', $svg);
        }
    }

    public function testAnsiForegroundBecomesTspanFill(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[31mred\x1b[0m text");
        // ANSI 31 = bright red @ #cd0000.
        $this->assertStringContainsString('fill="#cd0000"', $svg);
        $this->assertStringContainsString('red', $svg);
        $this->assertStringContainsString('text', $svg);
    }

    public function testAnsiBoldEmitsFontWeight(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[1mloud\x1b[0m");
        $this->assertStringContainsString('font-weight="bold"', $svg);
    }

    public function testTrueColorAnsi(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[38;2;255;128;0morange\x1b[0m");
        $this->assertStringContainsString('fill="#ff8000"', $svg);
    }

    public function testHtmlEscapeOnContent(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('<script>');
        // The literal text must be XML-escaped — never the raw `<script>`.
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
    }

    public function testAnsiBackgroundBecomesRectFill(): void
    {
        // ANSI 44 = blue background
        $svg = SvgRenderer::dark()->render("\x1b[44mblue background\x1b[0m text");
        // ANSI 44 = #0000ee (blue)
        $this->assertStringContainsString('fill="#0000ee"', $svg);
        $this->assertStringContainsString('blue background', $svg);
    }

    public function testEmptyInputRendersMinimalFrame(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render("");
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
        $this->assertStringContainsString('fill="#0d1117"', $svg);
    }
}
