<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

/**
 * The dashboard palette — every colour the Renderer paints.
 *
 * Immutable value object per repo law: a private constructor with public
 * readonly properties and a `::default()` factory, so a built Theme can
 * never drift under a live frame. The defaults are EXACTLY the hexes the
 * Renderer shipped since the rename, which is what keeps every existing
 * golden byte-identical across the extraction (CT-S33); named palettes
 * hang off this class as further factories (CT-S34).
 */
final class Theme
{
    private function __construct(
        public readonly string $title = '#ff5f87',
        public readonly string $range = '#a78bfa',
        public readonly string $total = '#6ee7b7',
        public readonly string $projects = '#fde68a',
        public readonly string $ranking = '#7dd3fc',
        public readonly string $muted = '#7d6e98',
        public readonly string $error = '#f87171',
        public readonly string $note = '#fca5a5',
    ) {
    }

    /** The shipped palette: the only one that predates theme selection. */
    public static function default(): self
    {
        return new self();
    }

    /** @var list<string> the names the CLI door accepts, listing order fixed */
    public const NAMES = ['default', 'light', 'dracula', 'solarized', 'gruvbox', 'high-contrast'];

    /**
     * The named palettes: default keeps every documented byte, light answers
     * the pastel-on-white complaint (the shipped candy tones wash out on a
     * white terminal), and the rest ride the well-known scheme families.
     * Unknown names answer null — the CLI refuses them, never guesses.
     */
    public static function named(string $name): ?self
    {
        return match ($name) {
            'default' => self::default(),
            'light' => new self('#c2185b', '#5e35b1', '#00695c', '#7a5901', '#0277bd', '#616161', '#b91c1c', '#be185d'),
            'dracula' => new self('#ff79c6', '#bd93f9', '#50fa7b', '#f1fa8c', '#8be9fd', '#6272a4', '#ff5555', '#ffb86c'),
            'solarized' => new self('#d33682', '#6c71c4', '#859900', '#b58900', '#2aa198', '#93a1a1', '#dc322f', '#cb4b16'),
            'gruvbox' => new self('#d3869b', '#83a598', '#b8bb26', '#fabd2f', '#8ec07c', '#928374', '#fb4934', '#fe8019'),
            'high-contrast' => new self('#ffffff', '#00ffff', '#00ff00', '#ffff00', '#5fd7ff', '#a8a8a8', '#ff0000', '#ffaf00'),
            default => null,
        };
    }
}
