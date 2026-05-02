<?php

declare(strict_types=1);

namespace CandyCore\Kit;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

/**
 * Per-status palette used by {@see StatusLine} and {@see Banner}.
 * Themes are immutable; {@see ansi()} ships the default colourful
 * palette, {@see plain()} produces a no-op palette ideal for
 * snapshot tests, and a handful of named presets (`charm`,
 * `dracula`, `nord`, `catppuccin`) cover the most-requested
 * branded palettes.
 */
final class Theme
{
    public function __construct(
        public readonly Style $success,
        public readonly Style $error,
        public readonly Style $warn,
        public readonly Style $info,
        public readonly Style $prompt,
        public readonly Style $accent,
        public readonly Style $muted,
    ) {}

    public static function ansi(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::ansi(10)),  // bright green
            error:   Style::new()->bold()->foreground(Color::ansi(9)),   // bright red
            warn:    Style::new()->bold()->foreground(Color::ansi(11)),  // bright yellow
            info:    Style::new()->bold()->foreground(Color::ansi(12)),  // bright blue
            prompt:  Style::new()->bold()->foreground(Color::hex('#ff5f87')),
            accent:  Style::new()->bold()->foreground(Color::ansi(13)),  // bright magenta
            muted:   Style::new()->faint(),
        );
    }

    public static function plain(): self
    {
        $s = Style::new();
        return new self($s, $s, $s, $s, $s, $s, $s);
    }

    /** Charm-brand pink + cyan accent set. */
    public static function charm(): self
    {
        $pink = Color::hex('#ff5fd2');
        $cyan = Color::hex('#5fafff');
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#5fff87')),
            error:   Style::new()->bold()->foreground(Color::hex('#ff5f5f')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ffd75f')),
            info:    Style::new()->bold()->foreground($cyan),
            prompt:  Style::new()->bold()->foreground($pink),
            accent:  Style::new()->bold()->foreground($pink),
            muted:   Style::new()->foreground(Color::hex('#888888')),
        );
    }

    /** Dracula palette. */
    public static function dracula(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#50fa7b')),
            error:   Style::new()->bold()->foreground(Color::hex('#ff5555')),
            warn:    Style::new()->bold()->foreground(Color::hex('#f1fa8c')),
            info:    Style::new()->bold()->foreground(Color::hex('#8be9fd')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#ff79c6')),
            accent:  Style::new()->bold()->foreground(Color::hex('#bd93f9')),
            muted:   Style::new()->foreground(Color::hex('#6272a4')),
        );
    }

    /** Nord palette — cool blues and frost tones. */
    public static function nord(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a3be8c')),
            error:   Style::new()->bold()->foreground(Color::hex('#bf616a')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ebcb8b')),
            info:    Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#5e81ac')),
            accent:  Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            muted:   Style::new()->foreground(Color::hex('#4c566a')),
        );
    }

    /** Catppuccin Mocha — pastel set. */
    public static function catppuccin(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a6e3a1')),
            error:   Style::new()->bold()->foreground(Color::hex('#f38ba8')),
            warn:    Style::new()->bold()->foreground(Color::hex('#f9e2af')),
            info:    Style::new()->bold()->foreground(Color::hex('#94e2d5')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            accent:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            muted:   Style::new()->foreground(Color::hex('#a6adc8')),
        );
    }
}
