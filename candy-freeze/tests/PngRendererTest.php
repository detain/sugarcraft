<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\PngRenderer;
use SugarCraft\Freeze\Theme;
use PHPUnit\Framework\TestCase;

final class PngRendererTest extends TestCase
{
    public function testRendersPng(): void
    {
        $png = PngRenderer::dark()->render("hello world\n");
        // PNG signature: \x89PNG\r\n\x1a\n
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testRespectsPadding(): void
    {
        $small = PngRenderer::dark()->withPadding(10)->render("x\n");
        $large = PngRenderer::dark()->withPadding(50)->render("x\n");
        // Larger padding = larger image.
        $this->assertGreaterThan(strlen($small), strlen($large));
    }

    public function testRespectsWindow(): void
    {
        $withWindow = PngRenderer::dark()->withWindow(true)->render("x\n");
        $withoutWindow = PngRenderer::dark()->withWindow(false)->render("x\n");
        // Window adds height, so size differs.
        $this->assertGreaterThan(strlen($withoutWindow), strlen($withWindow));
    }

    public function testRespectsBackgroundColor(): void
    {
        // Dark theme: #0d1117
        $dark = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("x\n");
        // Light theme: #f6f8fa
        $light = PngRenderer::light()->withWindow(false)->withShadow(false)->render("x\n");
        // Different background colours produce different bytes.
        $this->assertNotEquals($dark, $light);
    }

    public function testEmptyRendersMinimal(): void
    {
        $png = PngRenderer::dark()->withWindow(false)->render("");
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testWithTheme(): void
    {
        $dracula = PngRenderer::dracula()->withWindow(false)->withShadow(false)->render("x\n");
        $tokyo   = PngRenderer::tokyoNight()->withWindow(false)->withShadow(false)->render("x\n");
        // Different themes produce different output.
        $this->assertNotEquals($dracula, $tokyo);
    }

    public function testAnsiColorsParsed(): void
    {
        // ANSI 31 = bright red @ #cd0000.
        $png = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("\x1b[31mred\x1b[0m text\n");
        // Just verify it renders without error and is valid PNG.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testThemePresetsProduceDifferentOutput(): void
    {
        $presets = [
            PngRenderer::dark(),
            PngRenderer::light(),
            PngRenderer::dracula(),
            PngRenderer::tokyoNight(),
            PngRenderer::nord(),
        ];

        $hashes = [];
        foreach ($presets as $renderer) {
            $png = $renderer->withWindow(false)->withShadow(false)->render("x\n");
            $hashes[] = md5($png);
        }

        // All 5 themes should produce distinct output.
        $this->assertCount(5, array_unique($hashes));
    }

    public function testShadowIncreasesSize(): void
    {
        $noShadow = PngRenderer::dark()->withShadow(false)->render("x\n");
        $withShadow = PngRenderer::dark()->withShadow(true)->render("x\n");
        // Shadow adds margin, so size is larger.
        $this->assertGreaterThan(strlen($noShadow), strlen($withShadow));
    }

    public function testLineNumbersIncreaseWidth(): void
    {
        $noLineNums = PngRenderer::dark()->withLineNumbers(false)->withWindow(false)->render("x\n");
        $withLineNums = PngRenderer::dark()->withLineNumbers(true)->withWindow(false)->render("x\n");
        // Line numbers add gutter, so size is larger.
        $this->assertGreaterThan(strlen($noLineNums), strlen($withLineNums));
    }

    public function testRenderTwiceInOneProcessProducesIdenticalOutput(): void
    {
        $first  = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("hello\n");
        $second = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("hello\n");
        $this->assertSame($first, $second);
    }

    public function testWindowStylesProduceDistinctOutput(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not loaded');
        }

        $styles = ['macos', 'windows-terminal', 'iterm', 'hyper'];
        $outputs = [];
        foreach ($styles as $style) {
            $outputs[$style] = PngRenderer::dark()
                ->withWindowStyle($style)
                ->withShadow(false)
                ->render("x\n");
        }

        $values = array_values($outputs);
        for ($i = 0; $i < count($values); $i++) {
            for ($j = $i + 1; $j < count($values); $j++) {
                $this->assertNotSame(
                    $values[$i],
                    $values[$j],
                    "Window style $styles[$i] and $styles[$j] produced identical output",
                );
            }
        }
    }
}
