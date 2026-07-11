<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Dash\Plot\Chart\Bar;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Components\Card\Text;

/**
 * Sugar-dash inline-termui Theme (10 colour slots + helper methods
 * bar(), text(), fg(), bg(), color(), highlight()). Intentionally
 * distinct from \SugarCraft\Sprinkles\Theme (13 colour slots: adds
 * muted, info, border, separator, cursor; readonly properties only,
 * no helper methods). Both are canonical for their lib.
 *
 * See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-theme-ssot].
 */
final class Theme
{
    public function __construct(
        private readonly string $name,
        private readonly Color $foreground,
        private readonly Color $background,
        private readonly Color $primary,
        private readonly Color $secondary,
        private readonly Color $accent,
        private readonly Color $error,
        private readonly Color $warning,
        private readonly Color $success,
        private readonly Color $highlight,
    ) {}

    /**
     * Create the default dark theme (Tokyo Night inspired).
     */
    public static function dark(): self
    {
        return new self(
            name: 'dark',
            foreground: Color::hex('#C0CAF5'),
            background: Color::hex('#1A1B26'),
            primary: Color::hex('#7AA2F7'),
            secondary: Color::hex('#9ECE6A'),
            accent: Color::hex('#BB9AF7'),
            error: Color::hex('#F7768E'),
            warning: Color::hex('#E0AF68'),
            success: Color::hex('#9ECE6A'),
            highlight: Color::hex('#7AA2F7'),
        );
    }

    /**
     * Create the Dracula theme.
     *
     * Hex literals are re-sourced from candy-core's {@see Palettes::DRACULA}
     * SSOT so the scheme cannot drift from the shared palette. The constants
     * are lowercase `#rrggbb`; sugar-dash historically used uppercase, but the
     * rendered SGR bytes are numeric RGB, so the case change is cosmetic.
     */
    public static function dracula(): self
    {
        return new self(
            name: 'dracula',
            foreground: Color::hex(Palettes::DRACULA['foreground']),
            background: Color::hex(Palettes::DRACULA['background']),
            primary: Color::hex(Palettes::DRACULA['purple']),
            secondary: Color::hex(Palettes::DRACULA['green']),
            accent: Color::hex(Palettes::DRACULA['pink']),
            error: Color::hex(Palettes::DRACULA['red']),
            warning: Color::hex(Palettes::DRACULA['orange']),
            success: Color::hex(Palettes::DRACULA['green']),
            highlight: Color::hex(Palettes::DRACULA['purple']),
        );
    }

    /**
     * Create the One Dark theme.
     *
     * Hex literals are re-sourced from candy-core's {@see Palettes::ONE_DARK}
     * SSOT (lowercase `#rrggbb`); the previous uppercase literals rendered to
     * the same numeric-RGB SGR bytes, so the case change is cosmetic.
     */
    public static function oneDark(): self
    {
        return new self(
            name: 'one-dark',
            foreground: Color::hex(Palettes::ONE_DARK['foreground']),
            background: Color::hex(Palettes::ONE_DARK['background']),
            primary: Color::hex(Palettes::ONE_DARK['blue']),
            secondary: Color::hex(Palettes::ONE_DARK['green']),
            accent: Color::hex(Palettes::ONE_DARK['magenta']),
            error: Color::hex(Palettes::ONE_DARK['red']),
            warning: Color::hex(Palettes::ONE_DARK['yellow']),
            success: Color::hex(Palettes::ONE_DARK['green']),
            highlight: Color::hex(Palettes::ONE_DARK['blue']),
        );
    }

    /**
     * Create the GitHub Dark theme.
     *
     * Hex literals are re-sourced from candy-core's {@see Palettes::GITHUB_DARK}
     * SSOT (lowercase `#rrggbb`); the previous uppercase literals rendered to
     * the same numeric-RGB SGR bytes, so the case change is cosmetic.
     */
    public static function githubDark(): self
    {
        return new self(
            name: 'github-dark',
            foreground: Color::hex(Palettes::GITHUB_DARK['foreground']),
            background: Color::hex(Palettes::GITHUB_DARK['background']),
            primary: Color::hex(Palettes::GITHUB_DARK['blue']),
            secondary: Color::hex(Palettes::GITHUB_DARK['green']),
            accent: Color::hex(Palettes::GITHUB_DARK['pink']),
            error: Color::hex(Palettes::GITHUB_DARK['red']),
            warning: Color::hex(Palettes::GITHUB_DARK['yellow']),
            success: Color::hex(Palettes::GITHUB_DARK['green']),
            highlight: Color::hex(Palettes::GITHUB_DARK['blue']),
        );
    }

    /**
     * Create a light theme.
     */
    public static function light(): self
    {
        return new self(
            name: 'light',
            foreground: Color::hex('#383A42'),
            background: Color::hex('#FAFAFA'),
            primary: Color::hex('#4F46E5'),
            secondary: Color::hex('#10B981'),
            accent: Color::hex('#EC4899'),
            error: Color::hex('#EF4444'),
            warning: Color::hex('#F59E0B'),
            success: Color::hex('#10B981'),
            highlight: Color::hex('#4F46E5'),
        );
    }

    /**
     * Get the theme name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the foreground color.
     */
    public function foreground(): Color
    {
        return $this->foreground;
    }

    /**
     * Get the background color.
     */
    public function background(): Color
    {
        return $this->background;
    }

    /**
     * Get the primary color.
     */
    public function primary(): Color
    {
        return $this->primary;
    }

    /**
     * Get the secondary color.
     */
    public function secondary(): Color
    {
        return $this->secondary;
    }

    /**
     * Get the accent color.
     */
    public function accent(): Color
    {
        return $this->accent;
    }

    /**
     * Get the error color.
     */
    public function error(): Color
    {
        return $this->error;
    }

    /**
     * Get the warning color.
     */
    public function warning(): Color
    {
        return $this->warning;
    }

    /**
     * Get the success color.
     */
    public function success(): Color
    {
        return $this->success;
    }

    /**
     * Get the highlight color.
     */
    public function highlight(): Color
    {
        return $this->highlight;
    }

    /**
     * Get a color by name.
     */
    public function color(string $name): ?Color
    {
        return match ($name) {
            'foreground', 'fg' => $this->foreground,
            'background', 'bg' => $this->background,
            'primary' => $this->primary,
            'secondary' => $this->secondary,
            'accent' => $this->accent,
            'error' => $this->error,
            'warning' => $this->warning,
            'success' => $this->success,
            'highlight' => $this->highlight,
            default => null,
        };
    }

    /**
     * Create a styled bar with this theme's colors.
     */
    public function bar(string $content, ?HAlign $align = null): Bar
    {
        return Bar::new($content)
            ->withForeground($this->foreground)
            ->withBackground($this->background)
            ->withAlign($align ?? HAlign::Left);
    }

    /**
     * Create a styled text with this theme's foreground color.
     */
    public function text(string $content, ?HAlign $align = null): Text
    {
        $text = Text::new($content);
        if ($align !== null) {
            $text = $text->withHorizontalAlign($align);
        }
        return $text;
    }

    /**
     * Get the ANSI escape sequence for the foreground color.
     */
    public function fg(ColorProfile $profile = ColorProfile::TrueColor): string
    {
        return $this->foreground->toFg($profile);
    }

    /**
     * Get the ANSI escape sequence for the background color.
     */
    public function bg(ColorProfile $profile = ColorProfile::TrueColor): string
    {
        return $this->background->toBg($profile);
    }

    /**
     * Create a new theme with a different name.
     */
    public function withName(string $name): self
    {
        return new self(
            name: $name,
            foreground: $this->foreground,
            background: $this->background,
            primary: $this->primary,
            secondary: $this->secondary,
            accent: $this->accent,
            error: $this->error,
            warning: $this->warning,
            success: $this->success,
            highlight: $this->highlight,
        );
    }

    /**
     * Create a new theme with different foreground color.
     */
    public function withForeground(Color $color): self
    {
        return new self(
            name: $this->name,
            foreground: $color,
            background: $this->background,
            primary: $this->primary,
            secondary: $this->secondary,
            accent: $this->accent,
            error: $this->error,
            warning: $this->warning,
            success: $this->success,
            highlight: $this->highlight,
        );
    }

    /**
     * Create a new theme with different background color.
     */
    public function withBackground(Color $color): self
    {
        return new self(
            name: $this->name,
            foreground: $this->foreground,
            background: $color,
            primary: $this->primary,
            secondary: $this->secondary,
            accent: $this->accent,
            error: $this->error,
            warning: $this->warning,
            success: $this->success,
            highlight: $this->highlight,
        );
    }

    /**
     * Create a new theme with different primary color.
     */
    public function withPrimary(Color $color): self
    {
        return new self(
            name: $this->name,
            foreground: $this->foreground,
            background: $this->background,
            primary: $color,
            secondary: $this->secondary,
            accent: $this->accent,
            error: $this->error,
            warning: $this->warning,
            success: $this->success,
            highlight: $this->highlight,
        );
    }
}
