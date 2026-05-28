<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
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
}
