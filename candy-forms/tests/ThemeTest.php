<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Theme;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    /**
     * @return iterable<array{string, callable(): Theme}>
     */
    public static function themeFactories(): iterable
    {
        yield 'ansi()'      => ['ansi',      Theme::ansi(...)];
        yield 'plain()'     => ['plain',     Theme::plain(...)];
        yield 'charm()'     => ['charm',     Theme::charm(...)];
        yield 'dracula()'   => ['dracula',   Theme::dracula(...)];
        yield 'catppuccin()' => ['catppuccin', Theme::catppuccin(...)];
        yield 'base16()'    => ['base16',    Theme::base16(...)];
        yield 'base()'      => ['base',      Theme::base(...)];
    }

    /**
     * @dataProvider themeFactories
     */
    public function testFactoryReturnsThemeInstance(string $name, callable $factory): void
    {
        $theme = $factory();
        $this->assertInstanceOf(Theme::class, $theme);
    }

    /**
     * @dataProvider themeFactories
     */
    public function testAllStylePropertiesAreNonNull(string $name, callable $factory): void
    {
        $theme = $factory();
        $this->assertInstanceOf(Style::class, $theme->title);
        $this->assertInstanceOf(Style::class, $theme->description);
        $this->assertInstanceOf(Style::class, $theme->focusedTitle);
        $this->assertInstanceOf(Style::class, $theme->blurredTitle);
        $this->assertInstanceOf(Style::class, $theme->error);
        $this->assertInstanceOf(Style::class, $theme->cursor);
        $this->assertInstanceOf(Style::class, $theme->option);
        $this->assertInstanceOf(Style::class, $theme->selectedOption);
        $this->assertInstanceOf(Style::class, $theme->help);
        $this->assertInstanceOf(Style::class, $theme->prompt);
    }

    public function testAnsiThemeRendersTitleWithBold(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->title->render('Hello');
        // Bold emits SGR code 1
        $this->assertStringStartsWith("\x1b[1m", $rendered);
        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testAnsiThemeRendersFocusedTitleWithBold(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->focusedTitle->render('Focused');
        $this->assertStringStartsWith("\x1b[1m", $rendered);
        $this->assertStringContainsString('Focused', $rendered);
    }

    public function testAnsiThemeRendersBlurredTitleFaintly(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->blurredTitle->render('Blurred');
        // Faint emits SGR code 2
        $this->assertStringStartsWith("\x1b[2m", $rendered);
        $this->assertStringContainsString('Blurred', $rendered);
    }

    public function testAnsiThemeRendersErrorWithRedForeground(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->error->render('Error!');
        // True-color red: 38;2;255;0;0
        $this->assertStringContainsString("\x1b[38;2;255;0;0m", $rendered);
        $this->assertStringContainsString('Error!', $rendered);
    }

    public function testAnsiThemeRendersCursorWithReverse(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->cursor->render('|');
        // Reverse emits SGR code 7
        $this->assertStringStartsWith("\x1b[7m", $rendered);
    }

    public function testAnsiThemeRendersPromptWithCyanForeground(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->prompt->render('$');
        // True-color cyan: 38;2;0;255;255
        $this->assertStringContainsString("\x1b[38;2;0;255;255m", $rendered);
    }

    public function testAnsiThemeRendersSelectedOptionBold(): void
    {
        $theme = Theme::ansi();
        $rendered = $theme->selectedOption->render('* Selected');
        // Bold + color
        $this->assertStringStartsWith("\x1b[1m", $rendered);
    }

    public function testPlainThemeRendersTextVerbatim(): void
    {
        $theme = Theme::plain();
        // Plain theme should render text without any ANSI codes
        $this->assertSame('Hello', $theme->title->render('Hello'));
        $this->assertSame('World', $theme->description->render('World'));
        $this->assertSame('Error!', $theme->error->render('Error!'));
    }

    public function testPlainThemeAllStylesAreSameInstance(): void
    {
        $theme = Theme::plain();
        // Plain theme uses the same empty Style::new() instance for all slots
        $this->assertSame($theme->title, $theme->description);
        $this->assertSame($theme->description, $theme->focusedTitle);
        $this->assertSame($theme->focusedTitle, $theme->blurredTitle);
        $this->assertSame($theme->blurredTitle, $theme->error);
        $this->assertSame($theme->error, $theme->cursor);
        $this->assertSame($theme->cursor, $theme->option);
        $this->assertSame($theme->option, $theme->selectedOption);
        $this->assertSame($theme->selectedOption, $theme->help);
        $this->assertSame($theme->help, $theme->prompt);
    }

    public function testCharmThemeUsesPinkAndCyan(): void
    {
        $theme = Theme::charm();
        $titleRendered = $theme->title->render('Title');
        $promptRendered = $theme->prompt->render('$');
        // Pink #ff5fd2 = rgb(255,95,210), Cyan #5fafff = rgb(95,175,255)
        $this->assertStringContainsString("\x1b[38;2;255;95;210m", $titleRendered);
        $this->assertStringContainsString("\x1b[38;2;95;175;255m", $promptRendered);
    }

    public function testDraculaThemeUsesDraculaColors(): void
    {
        $theme = Theme::dracula();
        $titleRendered = $theme->title->render('Title');
        // Pink #ff79c6 = rgb(255,121,198)
        $this->assertStringContainsString("\x1b[38;2;255;121;198m", $titleRendered);
    }

    public function testCatppuccinThemeUsesPastelColors(): void
    {
        $theme = Theme::catppuccin();
        $titleRendered = $theme->title->render('Title');
        // Mauve #cba6f7 = rgb(203,166,247)
        $this->assertStringContainsString("\x1b[38;2;203;166;247m", $titleRendered);
    }

    public function testBase16ThemeUsesBase16Colors(): void
    {
        $theme = Theme::base16();
        $titleRendered = $theme->title->render('Title');
        // Accent #cc6666 = rgb(204,102,102)
        $this->assertStringContainsString("\x1b[38;2;204;102;102m", $titleRendered);
    }

    public function testBaseThemeIsMonochrome(): void
    {
        $theme = Theme::base();
        $titleRendered = $theme->title->render('Title');
        $promptRendered = $theme->prompt->render('$');
        // Base theme is bold but no colors (no 38;2; true-color codes)
        $this->assertStringStartsWith("\x1b[1m", $titleRendered);
        $this->assertStringStartsWith("\x1b[1m", $promptRendered);
        // No true-color ANSI codes (38;2;)
        $this->assertStringNotContainsString("\x1b[38;2;", $titleRendered);
        $this->assertStringNotContainsString("\x1b[38;2;", $promptRendered);
    }

    public function testBaseThemeFocusedTitleHasBoldAndUnderline(): void
    {
        $theme = Theme::base();
        $rendered = $theme->focusedTitle->render('Focused');
        // Should have bold (1) and underline (4) combined as 1;4
        $this->assertStringContainsString("\x1b[1;4m", $rendered);
    }

    public function testDifferentThemesProduceDifferentStyles(): void
    {
        $ansiRendered    = Theme::ansi()->title->render('T');
        $plainRendered   = Theme::plain()->title->render('T');
        $charmRendered   = Theme::charm()->title->render('T');
        $draculaRendered = Theme::dracula()->title->render('T');

        // Plain has no ANSI codes, others do
        $this->assertNotSame($plainRendered, $ansiRendered);
        $this->assertNotSame($plainRendered, $charmRendered);
        // ANSI and Charm have different colors
        $this->assertNotSame($ansiRendered, $charmRendered);
        // ANSI and Dracula have different colors
        $this->assertNotSame($ansiRendered, $draculaRendered);
    }
}
