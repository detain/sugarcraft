<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

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
     * Returns only the v1-ready themes (TokyoNight first, others as stubs).
     *
     * @return array<string, Theme>
     */
    public static function v1(): array
    {
        return [
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
        ];
    }
}
