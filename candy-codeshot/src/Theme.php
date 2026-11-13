<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

use SugarCraft\Core\Util\Palettes;

/**
 * Visual theme shared by {@see SvgRenderer} and {@see PngRenderer}.
 * Carries the colours of every surface the renderers paint
 * (background, foreground, frame chrome, window controls, line
 * highlight band), plus
 * typographic settings (font family, size, line height). The active
 * window-chrome style lives on the renderers, not the theme.
 *
 * Related to charmbracelet/freeze's `Theme` but not a mirror of it:
 * freeze sources its chrome (border, shadow, window buttons) from a
 * separate style.Theme, while this class carries those chrome slots
 * directly, and it additionally carries the nullable syntax-token slots
 * below, which freeze has no equivalent for. Built-in presets cover the
 * common dark / light / Dracula / Tokyo Night / Nord palettes.
 *
 * Chrome and syntax are two separate axes (cs-07): the window buttons,
 * border and gutter are chrome, and imported themes may only paint the
 * syntax slots — {@see \SugarCraft\CodeShot\Theme\VsCodeThemeLoader} and
 * {@see \SugarCraft\CodeShot\Theme\FlatPaletteThemeLoader} route token scopes
 * here, never onto the window colours, so a `keyword` scope can no
 * longer tint the traffic lights. Null token slots fall back to the
 * contrast heuristic in {@see CodeHighlighter::forTheme()}.
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
        public readonly ?string $highlight = null,
        /**
         * Syntax-token colours a theme carries for code text (the loaders'
         * own vocabularies). Null means "the theme says nothing about this
         * token" and {@see CodeHighlighter::forTheme()} falls back to its
         * contrast heuristic. The renderers never paint chrome from these.
         */
        public readonly ?string $keyword = null,
        public readonly ?string $string = null,
        public readonly ?string $number = null,
        public readonly ?string $comment = null,
        public readonly ?string $type = null,
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
            highlight:    '#2d333b',
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
            highlight:    '#fff9c4',
        );
    }

    public static function dracula(): self
    {
        return new self(
            background:   Palettes::DRACULA['background'],
            foreground:   Palettes::DRACULA['foreground'],
            border:       Palettes::DRACULA['currentLine'],
            shadow:       'rgba(0, 0, 0, 0.5)', // not a Dracula palette colour — keep as literal
            lineNumber:   Palettes::DRACULA['comment'],
            windowRed:    Palettes::DRACULA['red'],
            // Candy-codeshot deliberately uses Dracula 'yellow' for the window button;
            // this differs from sugar-style's 'warning' → 'orange' mapping.
            windowYellow: Palettes::DRACULA['yellow'],
            windowGreen:  Palettes::DRACULA['green'],
            highlight:    '#44475a',
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
            highlight:    '#2f334d',
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
            highlight:    '#3b4252',
        );
    }
}
