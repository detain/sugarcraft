<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\SvgRenderer;
use SugarCraft\Freeze\Theme;
use SugarCraft\Freeze\WindowStyle;
use PHPUnit\Framework\TestCase;

final class WindowStyleTest extends TestCase
{
    public function testDefaultWindowStyleIsMacos(): void
    {
        $renderer = SvgRenderer::dark();
        $this->assertSame(WindowStyle::Macos, $renderer->windowStyle);
    }

    public function testWindowStyleMacosRendersTrafficLights(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::Macos)
            ->render('x');
        // Three traffic-light circles.
        $this->assertSame(3, substr_count($svg, '<circle'));
    }

    public function testWindowStyleWindowsTerminalRendersTitleBar(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::WindowsTerminal)
            ->render('x');
        // Windows terminal has rect elements for title bar and buttons (no circles).
        $this->assertStringContainsString('fill="#1e1e1e"', $svg);
        $this->assertStringContainsString('fill="#444444"', $svg);
        // Should not have traffic light circles.
        $this->assertSame(0, substr_count($svg, '<circle'));
    }

    public function testWindowStyleITerm2RendersSmallerTrafficLights(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::ITerm2)
            ->render('x');
        // iTerm2 has smaller traffic lights (3 circles).
        $this->assertSame(3, substr_count($svg, '<circle'));
        // iTerm2 has r="4" (smaller radius).
        $this->assertStringContainsString('r="4"', $svg);
    }

    public function testWindowStyleHyperRendersTitleBarWithTrafficLights(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::Hyper)
            ->render('x');
        // Hyper has a title bar (rect) and traffic lights (circles).
        $this->assertStringContainsString('<rect', $svg);
        $this->assertSame(3, substr_count($svg, '<circle'));
    }

    public function testWindowStyleNoneRendersNoChrome(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::None)
            ->render('x');
        // No circles, no title bar rects.
        $this->assertSame(0, substr_count($svg, '<circle'));
        $this->assertStringNotContainsString('fill="#1e1e1e"', $svg);
    }

    public function testWindowStylePreservedInWithChain(): void
    {
        $renderer = SvgRenderer::dark()
            ->withPadding(32)
            ->withWindowStyle(WindowStyle::ITerm2)
            ->withLineNumbers(true);

        $this->assertSame(WindowStyle::ITerm2, $renderer->windowStyle);
        $this->assertSame(32, $renderer->padding);
        $this->assertTrue($renderer->lineNumbers);
    }

    public function testWindowStyleFromString(): void
    {
        $renderer = SvgRenderer::dark()->withWindowStyle('iterm');
        $this->assertSame(WindowStyle::ITerm2, $renderer->windowStyle);
    }

    public function testAllWindowStylesRenderValidSvg(): void
    {
        $text = "hello\nworld";
        foreach (WindowStyle::cases() as $style) {
            $svg = SvgRenderer::dark()->withWindowStyle($style)->render($text);
            $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
            $this->assertStringContainsString('<svg', $svg);
            $this->assertStringEndsWith("</svg>\n", $svg);
        }
    }

    public function testMacosAndITerm2ProduceDifferentCircleCount(): void
    {
        $macosSvg = SvgRenderer::dark()->withWindowStyle(WindowStyle::Macos)->render('x');
        $itermSvg = SvgRenderer::dark()->withWindowStyle(WindowStyle::ITerm2)->render('x');

        // Both have 3 circles but at different positions (different radii/gaps).
        $this->assertSame(3, substr_count($macosSvg, '<circle'));
        $this->assertSame(3, substr_count($itermSvg, '<circle'));

        // They differ in the radius value.
        $this->assertStringContainsString('r="6"', $macosSvg);
        $this->assertStringContainsString('r="4"', $itermSvg);
    }

    public function testWindowsTerminalHasNoTrafficLights(): void
    {
        $svg = SvgRenderer::dark()
            ->withWindowStyle(WindowStyle::WindowsTerminal)
            ->render('x');
        // Windows Terminal style has rect buttons, not circles.
        $this->assertStringNotContainsString('<circle', $svg);
    }
}
