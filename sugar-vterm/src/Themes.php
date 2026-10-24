<?php

declare(strict_types=1);

namespace SugarCraft\Vterm;

/**
 * Catalog of available terminal themes.
 */
final class Themes
{
    /**
     * Returns the catalog of all available themes: name => Theme.
     *
     * @return array<string, Theme>
     */
    public static function all(): array
    {
        return [
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
            'Dracula' => Theme::dracula(),
            'SolarizedDark' => Theme::solarizedDark(),
        ];
    }

    /**
     * Returns the v1-ready themes (TokyoNight first).
     *
     * Every bundled theme carries a complete 16-slot ANSI palette plus the
     * shared extended palette, so the v1 roster is today identical to
     * {@see self::all()} — pinned by ThemeTest against the README table.
     *
     * @return array<string, Theme>
     */
    public static function v1(): array
    {
        return [
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
            'Dracula' => Theme::dracula(),
            'SolarizedDark' => Theme::solarizedDark(),
        ];
    }
}
