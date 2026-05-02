<?php

declare(strict_types=1);

namespace CandyCore\Kit;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

/**
 * Per-status palette used by {@see StatusLine} and {@see Banner}.
 * Themes are immutable; {@see ansi()} ships the default colourful
 * palette, {@see plain()} produces a no-op palette ideal for
 * snapshot tests.
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
}
