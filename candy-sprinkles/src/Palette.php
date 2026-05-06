<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Color;

/**
 * Named ANSI colour constants and colour helpers — the lipgloss equivalents
 * of `lipgloss.Black`, `lipgloss.Red`, …, `lipgloss.BrightWhite` plus the
 * `lipgloss.HasDarkBackground()` top-level helper.
 *
 * Each colour factory returns a fresh {@see Color} value. This is a thin
 * convenience wrapper so callers don't need to remember the 16 ANSI indices;
 * use `Color::rgb()` / `Color::hex()` / `Color::hsl()` for everything else.
 *
 * Example:
 *
 *     $style = Style::new()
 *         ->foreground(Palette::brightCyan())
 *         ->background(Palette::black());
 */
final class Palette
{
    public static function black():        Color { return Color::ansi(0);  }
    public static function red():          Color { return Color::ansi(1);  }
    public static function green():        Color { return Color::ansi(2);  }
    public static function yellow():       Color { return Color::ansi(3);  }
    public static function blue():         Color { return Color::ansi(4);  }
    public static function magenta():      Color { return Color::ansi(5);  }
    public static function cyan():         Color { return Color::ansi(6);  }
    public static function white():        Color { return Color::ansi(7);  }
    public static function brightBlack():   Color { return Color::ansi(8);  }
    public static function brightRed():     Color { return Color::ansi(9);  }
    public static function brightGreen():   Color { return Color::ansi(10); }
    public static function brightYellow():  Color { return Color::ansi(11); }
    public static function brightBlue():    Color { return Color::ansi(12); }
    public static function brightMagenta(): Color { return Color::ansi(13); }
    public static function brightCyan():    Color { return Color::ansi(14); }
    public static function brightWhite():   Color { return Color::ansi(15); }

    /** Alias for `brightBlack()` — the canonical "muted" palette slot. */
    public static function gray(): Color { return self::brightBlack(); }

    /**
     * lipgloss-style top-level helper: given a known background {@see Color}
     * (typically obtained via a CandyCore terminal-query response), report
     * whether it's dark enough to want light foregrounds.
     *
     * Returns null when no background is available — callers should treat
     * null as "unknown, don't switch palette" rather than as a default.
     */
    public static function hasDarkBackground(?Color $background): ?bool
    {
        return $background?->isDark();
    }

    /**
     * All 16 named ANSI colours in ANSI index order. Useful for theme
     * preview tables and for iteration tests.
     *
     * @return list<Color>
     */
    public static function all(): array
    {
        return [
            self::black(), self::red(), self::green(), self::yellow(),
            self::blue(), self::magenta(), self::cyan(), self::white(),
            self::brightBlack(), self::brightRed(), self::brightGreen(),
            self::brightYellow(), self::brightBlue(), self::brightMagenta(),
            self::brightCyan(), self::brightWhite(),
        ];
    }
}
