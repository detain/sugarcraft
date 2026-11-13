<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;

/**
 * The one name→palette-slot table shared by both markup parsers.
 *
 * StyleParser and Markup each hand-carried a 16-row name→RGB table (a
 * third and fourth definition of "red", driftable from core's ruled
 * ANSI16_RGB roster — finding 023). Names now resolve through
 * {@see Color::ansi()} slots instead of absolute RGB: the user's own
 * palette (Xresources, tmux, macOS Terminal themes) wins the final
 * colour at every tier, including TrueColor, where an absolute
 * `38;2;r;g;b` byte overrides exactly that. The xterm-ANSI16 *values*
 * the slots still carry are sugar-core's dated owner ruling — untouched
 * here; only the routing changes.
 *
 * @internal Shared plumbing for {@see StyleParser} and {@see Markup}.
 *           Not public API.
 */
final class NamedColors
{
    /**
     * Lowercased name → ANSI-16 slot index (the keys of core's
     * Color::ANSI16_RGB, in its order).
     */
    private const SLOTS = [
        'black' => 0,
        'red' => 1,
        'green' => 2,
        'yellow' => 3,
        'blue' => 4,
        'magenta' => 5,
        'cyan' => 6,
        'white' => 7,
        'bright-black' => 8,
        'bright-red' => 9,
        'bright-green' => 10,
        'bright-yellow' => 11,
        'bright-blue' => 12,
        'bright-magenta' => 13,
        'bright-cyan' => 14,
        'bright-white' => 15,
    ];

    /**
     * Resolve a colour name to its palette-slot Color, or null when the
     * word is not one of the sixteen (callers then keep their own
     * hex/unknown handling).
     */
    public static function color(string $name): ?Color
    {
        $lower = strtolower($name);

        return isset(self::SLOTS[$lower])
            ? Color::ansi(self::SLOTS[$lower])
            : null;
    }
}
