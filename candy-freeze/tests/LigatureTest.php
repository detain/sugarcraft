<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\SvgRenderer;
use PHPUnit\Framework\TestCase;

final class LigatureTest extends TestCase
{
    public function testLigaturesDisabledByDefault(): void
    {
        $svg = SvgRenderer::dark()->render("hello\n");
        $this->assertStringNotContainsString('font-variant-ligatures', $svg);
    }

    public function testWithLigaturesAddsFontVariantLigaturesAttr(): void
    {
        $svg = SvgRenderer::dark()->withLigatures(true)->render("hello\n");
        $this->assertStringContainsString('font-variant-ligatures="normal"', $svg);
    }

    public function testWithLigaturesFalseRemovesAttr(): void
    {
        $svg = SvgRenderer::dark()->withLigatures(true)->withLigatures(false)->render("hello\n");
        $this->assertStringNotContainsString('font-variant-ligatures', $svg);
    }

    public function testLigaturesFluentChain(): void
    {
        $renderer = SvgRenderer::dark()
            ->withWindow(false)
            ->withLigatures(true)
            ->withShadow(false);

        $this->assertTrue($renderer->ligatures);
        $svg = $renderer->render("fi\n");
        $this->assertStringContainsString('font-variant-ligatures="normal"', $svg);
    }

    public function testLigaturesWithAnsiStyledText(): void
    {
        $svg = SvgRenderer::dark()
            ->withLigatures(true)
            ->render("\x1b[1mfi\x1b[0m");

        $this->assertStringContainsString('font-variant-ligatures="normal"', $svg);
        $this->assertStringContainsString('font-weight="bold"', $svg);
    }
}
