<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Theme management for consistent styling across components.
 *
 * Features:
 * - Pre-defined themes (Tokyo Night, Dracula, One Dark, etc.)
 * - Custom theme creation
 * - Style retrieval for components
 * - Editor/Syntax highlighting theme support
 *
 * Mirrors theming from bubble-lipgloss but adapted to PHP
 * with wither-style immutable setters.
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
     */
    public static function dracula(): self
    {
        return new self(
            name: 'dracula',
            foreground: Color::hex('#F8F8F2'),
            background: Color::hex('#282A36'),
            primary: Color::hex('#BD93F9'),
            secondary: Color::hex('#50FA7B'),
            accent: Color::hex('#FF79C6'),
            error: Color::hex('#FF5555'),
            warning: Color::hex('#FFB86C'),
            success: Color::hex('#50FA7B'),
            highlight: Color::hex('#BD93F9'),
        );
    }

    /**
     * Create the One Dark theme.
     */
    public static function oneDark(): self
    {
        return new self(
            name: 'one-dark',
            foreground: Color::hex('#ABB2BF'),
            background: Color::hex('#282C34'),
            primary: Color::hex('#61AFEF'),
            secondary: Color::hex('#98C379'),
            accent: Color::hex('#C678DD'),
            error: Color::hex('#E06C75'),
            warning: Color::hex('#E5C07B'),
            success: Color::hex('#98C379'),
            highlight: Color::hex('#61AFEF'),
        );
    }

    /**
     * Create the GitHub Dark theme.
     */
    public static function githubDark(): self
    {
        return new self(
            name: 'github-dark',
            foreground: Color::hex('#C9D1D9'),
            background: Color::hex('#0D1117'),
            primary: Color::hex('#58A6FF'),
            secondary: Color::hex('#3FB950'),
            accent: Color::hex('#F778BA'),
            error: Color::hex('#F85149'),
            warning: Color::hex('#D29922'),
            success: Color::hex('#3FB950'),
            highlight: Color::hex('#58A6FF'),
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
        $clone = clone $this;
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
