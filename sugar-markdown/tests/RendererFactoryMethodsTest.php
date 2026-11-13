<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererFactoryMethodsTest extends TestCase
{
    public function testAnsiFactory(): void
    {
        $r = Renderer::ansi();
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render('# Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testPlainFactory(): void
    {
        $r = Renderer::plain();
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render('# Hello');
        $this->assertSame('# Hello', $out);
    }

    public function testAsciiFactory(): void
    {
        $r = Renderer::ascii();
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render('# Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testFromEnvironmentFactory(): void
    {
        $r = Renderer::fromEnvironment();
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render('# Hello');
        $this->assertStringContainsString('Hello', $out);
    }

    public function testBaseURLChain(): void
    {
        $r = Renderer::plain()->baseURL('https://example.com/');
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->withHyperlinks(false)->render('[home](readme.md)');
        $this->assertStringContainsString('(https://example.com/readme.md)', $out);
    }

    public function testInlineTableLinksChain(): void
    {
        $r = Renderer::plain()->inlineTableLinks(false);
        $this->assertInstanceOf(Renderer::class, $r);
    }

    public function testPreservedNewLinesChain(): void
    {
        $r = Renderer::plain()->preservedNewLines(true);
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render("first\n\n\n\n\nlast");
        $this->assertStringContainsString('first', $out);
        $this->assertStringContainsString('last', $out);
    }

    public function testStandardStyleChain(): void
    {
        $r = Renderer::ansi()->standardStyle('plain');
        $this->assertInstanceOf(Renderer::class, $r);
        $out = $r->render('# Hello');
        $this->assertSame('# Hello', $out);
    }

    /**
     * SM-S48: the refusal carries the glamour-faithful canonical slug
     * (the message derives from Theme::names(); this pin keeps a
     * reintroduced literal 'tokyonight' list from silently returning),
     * while the alias spelling still resolves through normalisation.
     */
    public function testStandardStyleRefusalSpellsTokyoNightCanonically(): void
    {
        try {
            Renderer::plain()->withStandardStyle('nope');
            $this->fail('unknown standard style must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tokyo-night', $e->getMessage());
            $this->assertStringNotContainsString('tokyonight', $e->getMessage());
        }
        $this->assertInstanceOf(
            Renderer::class,
            Renderer::plain()->withStandardStyle('tokyonight'),
            'the alias spelling stays accepted'
        );
    }
}
