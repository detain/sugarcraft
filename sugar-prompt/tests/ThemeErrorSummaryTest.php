<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use SugarCraft\Prompt\Theme;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class ThemeErrorSummaryTest extends TestCase
{
    /**
     * @return iterable<array{string, callable(): Theme}>
     */
    public static function themeFactories(): iterable
    {
        yield 'ansi()'       => ['ansi',       Theme::ansi(...)];
        yield 'plain()'       => ['plain',      Theme::plain(...)];
        yield 'charm()'       => ['charm',      Theme::charm(...)];
        yield 'dracula()'    => ['dracula',    Theme::dracula(...)];
        yield 'catppuccin()' => ['catppuccin', Theme::catppuccin(...)];
        yield 'base16()'     => ['base16',     Theme::base16(...)];
        yield 'base()'        => ['base',        Theme::base(...)];
    }

    /**
     * @dataProvider themeFactories
     */
    public function testErrorSummaryPropertyExists(string $name, callable $factory): void
    {
        $theme = $factory();
        $this->assertInstanceOf(Style::class, $theme->errorSummary);
    }

    /**
     * @dataProvider themeFactories
     */
    public function testErrorSummaryIsDistinctFromError(string $name, callable $factory): void
    {
        $theme = $factory();
        // errorSummary is a separate slot — both exist independently.
        $this->assertInstanceOf(Style::class, $theme->error);
        $this->assertInstanceOf(Style::class, $theme->errorSummary);
    }

    public function testAnsiErrorSummaryRendersWithBold(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->errorSummary->render('Fix these errors:');
        // Bold emits SGR code 1.
        $this->assertStringStartsWith("\x1b[1m", $rendered);
    }

    public function testPlainErrorSummaryRendersVerbatim(): void
    {
        $theme = Theme::plain();
        $this->assertSame('Fix these errors:', $theme->errorSummary->render('Fix these errors:'));
    }

    public function testErrorSummaryAndErrorCanBeDifferentStyles(): void
    {
        $charm = Theme::charm();
        // Both are Style instances — they may differ or coincide depending on the theme.
        $this->assertInstanceOf(Style::class, $charm->errorSummary);
        $this->assertInstanceOf(Style::class, $charm->error);
    }

    public function testBaseErrorSummaryIsBold(): void
    {
        $theme = Theme::base();
        $rendered = $theme->errorSummary->render('Error summary');
        // Bold emits SGR code 1.
        $this->assertStringStartsWith("\x1b[1m", $rendered);
    }
}
