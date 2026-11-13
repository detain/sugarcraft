<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
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

    /**
     * SM-S35: the factory set covers every stock theme Theme can mint,
     * so the shortcut door can no longer be named differently from the
     * long door. Identity of the supplied theme pins each wiring.
     */
    public function testThemeFactoriesCoverTheWholeStockSet(): void
    {
        $stock = [
            'ansi'       => Theme::ansi(),
            'plain'      => Theme::plain(),
            'ascii'      => Theme::ascii(),
            'notty'      => Theme::notty(),
            'dark'       => Theme::dark(),
            'light'      => Theme::light(),
            'dracula'    => Theme::dracula(),
            'tokyoNight' => Theme::tokyoNight(),
            'pink'       => Theme::pink(),
        ];
        foreach ($stock as $method => $theme) {
            $this->assertEquals($theme, Renderer::$method()->theme, "Renderer::{$method}() must carry Theme::{$method}()");
            $this->assertInstanceOf(Renderer::class, Renderer::$method());
        }
    }

    /**
     * SM-S33: the (Theme $default) call shape was a TypeError — the
     * caller's fallback could only be ansi. Unset, recognised and
     * unrecognised env spellings must all keep the documented law:
     * env wins, else the caller's theme, else ansi.
     */
    public function testFromEnvironmentForwardsTheDefaultTheme(): void
    {
        $style = getenv('GLAMOUR_STYLE');
        putenv('GLAMOUR_STYLE');
        $fallback = Theme::pink();
        try {
            $this->assertEquals(Theme::ansi(), Renderer::fromEnvironment()->theme, 'no default keeps the historic ansi floor');
            $this->assertEquals($fallback, Renderer::fromEnvironment($fallback)->theme, 'unset env lands on the default');
            putenv('GLAMOUR_STYLE=not-a-theme');
            $this->assertEquals($fallback, Renderer::fromEnvironment($fallback)->theme, 'unrecognised env lands on the default');
            putenv('GLAMOUR_STYLE=notty');
            $this->assertEquals(Theme::notty(), Renderer::fromEnvironment($fallback)->theme, 'the env still wins over the default');
        } finally {
            putenv($style === false ? 'GLAMOUR_STYLE' : "GLAMOUR_STYLE={$style}");
        }
    }
}
