<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Visual theme for {@see SvgRenderer}. Carries the colours of every
 * surface the renderer paints (background, foreground, frame chrome,
 * window controls), plus typographic settings (font family, size,
 * line height).
 *
 * Mirrors charmbracelet/freeze's `Theme`. Built-in presets cover the
 * common dark / light / Dracula / Tokyo Night / Nord palettes.
 */
final class Theme
{
    public function __construct(
        public readonly string $background,
        public readonly string $foreground,
        public readonly string $border,
        public readonly string $shadow,
        public readonly string $lineNumber,
        public readonly string $windowRed,
        public readonly string $windowYellow,
        public readonly string $windowGreen,
        public readonly string $fontFamily   = 'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
        public readonly float  $fontSize     = 14.0,
        public readonly float  $lineHeight   = 1.4,
        public readonly WindowStyle $windowStyle = WindowStyle::Macos,
    ) {}

    /** Default dark theme (charm-ish). */
    public static function dark(): self
    {
        return new self(
            background:   '#0d1117',
            foreground:   '#c9d1d9',
            border:       '#30363d',
            shadow:       'rgba(0, 0, 0, 0.5)',
            lineNumber:   '#6e7681',
            windowRed:    '#ff5f56',
            windowYellow: '#ffbd2e',
            windowGreen:  '#27c93f',
        );
    }

    public static function light(): self
    {
        return new self(
            background:   '#f6f8fa',
            foreground:   '#24292f',
            border:       '#d0d7de',
            shadow:       'rgba(0, 0, 0, 0.15)',
            lineNumber:   '#8c959f',
            windowRed:    '#ff5f56',
            windowYellow: '#ffbd2e',
            windowGreen:  '#27c93f',
        );
    }

    public static function dracula(): self
    {
        return new self(
            background:   '#282a36',
            foreground:   '#f8f8f2',
            border:       '#44475a',
            shadow:       'rgba(0, 0, 0, 0.5)',
            lineNumber:   '#6272a4',
            windowRed:    '#ff5555',
            windowYellow: '#f1fa8c',
            windowGreen:  '#50fa7b',
        );
    }

    public static function tokyoNight(): self
    {
        return new self(
            background:   '#1a1b26',
            foreground:   '#a9b1d6',
            border:       '#414868',
            shadow:       'rgba(0, 0, 0, 0.5)',
            lineNumber:   '#565f89',
            windowRed:    '#f7768e',
            windowYellow: '#e0af68',
            windowGreen:  '#9ece6a',
        );
    }

    public static function nord(): self
    {
        return new self(
            background:   '#2e3440',
            foreground:   '#d8dee9',
            border:       '#4c566a',
            shadow:       'rgba(0, 0, 0, 0.4)',
            lineNumber:   '#4c566a',
            windowRed:    '#bf616a',
            windowYellow: '#ebcb8b',
            windowGreen:  '#a3be8c',
        );
    }
}
