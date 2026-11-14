<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vterm\Theme;

/**
 * Inline JSON themes for `Set Theme {…}`.
 *
 * VHS accepts a JSON object as a theme value, so a tape can carry its colours
 * instead of naming a stock palette. Before this parser existed, such a value
 * reached the rasterizer as an unknown name and silently degraded to
 * TokyoNight — the tape's own colours never touched a pixel.
 *
 * The object shape follows upstream's theme fields. Our `Theme` model carries
 * default fg/bg indices plus a 0..255 palette, so the mapping mirrors how the
 * stock palettes themselves encode a background: `background` lands in slot 0
 * with `defaultBg = 0`, and `foreground` lands in slot 7 with `defaultFg = 7`
 * (the same convention `Theme::tokyoNight()` uses). The sixteen ANSI names map
 * to slots 0..15; a `background`/`foreground` given as well overrides those
 * two slots, because the terminal defaults are what unstyled cells paint with.
 * The `cursor`/`cursorText*` fields are accepted — they describe a hardware
 * cursor this renderer does not draw — and are otherwise inert.
 *
 * Slots the object omits stay unset in the palette; `Theme::color()` then
 * computes the standard xterm value for that index, so a tape that overrides
 * only `red` gets a sane remaining sixteen.
 *
 * Parsing is fail-closed: malformed JSON, a non-string colour, an unparsable
 * hex triplet, or an unrecognised key raises a named exception identifying the
 * offender, so a typo is never quietly swallowed.
 */
final class InlineTheme
{
    /** @var array<string, int> ANSI colour names → palette slots, xterm order. */
    private const SLOTS = [
        'black' => 0,
        'red' => 1,
        'green' => 2,
        'yellow' => 3,
        'blue' => 4,
        'magenta' => 5,
        'cyan' => 6,
        'white' => 7,
        'brightBlack' => 8,
        'brightRed' => 9,
        'brightGreen' => 10,
        'brightYellow' => 11,
        'brightBlue' => 12,
        'brightMagenta' => 13,
        'brightCyan' => 14,
        'brightWhite' => 15,
    ];

    /** Fields upstream carries that our grid renderer cannot express. */
    private const INERT = ['cursor', 'cursorTextLower', 'cursorTextUpper'];

    private function __construct()
    {
    }

    public static function looksLikeJson(string $theme): bool
    {
        return str_starts_with(trim($theme), '{');
    }

    /**
     * @throws \InvalidArgumentException on any structural or colour violation
     */
    public static function fromJson(string $json): Theme
    {
        try {
            $decoded = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                'inline theme is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded) || ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1))) {
            throw new \InvalidArgumentException('inline theme must be a JSON object of colour fields');
        }

        $palette = [];
        $defaultFg = 7;
        $defaultBg = 0;
        $background = null;
        $foreground = null;

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    "inline theme key '" . (string) $key . "' must be a colour name",
                );
            }

            if (in_array($key, self::INERT, true)) {
                // Accepted for upstream compatibility; never applied (see class doc).
                if ($value !== null && !is_string($value)) {
                    throw new \InvalidArgumentException("inline theme field '{$key}' must be a #RRGGBB string");
                }
                continue;
            }

            if ($key === 'background') {
                $background = self::parseHex($key, $value);
                continue;
            }

            if ($key === 'foreground') {
                $foreground = self::parseHex($key, $value);
                continue;
            }

            if (!isset(self::SLOTS[$key])) {
                throw new \InvalidArgumentException(
                    "inline theme field '{$key}' is not a known colour (known: background, foreground, "
                        . implode(', ', array_keys(self::SLOTS)) . ')',
                );
            }

            $palette[self::SLOTS[$key]] = self::parseHex($key, $value);
        }

        // Applied after the ANSI names regardless of JSON key order: the
        // terminal defaults are what unstyled cells paint with, so they win
        // the slots they share with `black`/`white` (see class doc).
        if ($background !== null) {
            $palette[0] = $background;
            $defaultBg = 0;
        }

        if ($foreground !== null) {
            $palette[7] = $foreground;
            $defaultFg = 7;
        }

        return new Theme(defaultFg: $defaultFg, defaultBg: $defaultBg, palette: $palette);
    }

    /**
     * `#RGB`, `#RRGGBB` and their bare forms are all accepted; anything else
     * names the offending field instead of guessing.
     */
    private static function parseHex(string $key, mixed $value): int
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("inline theme field '{$key}' must be a #RRGGBB string");
        }

        $hex = ltrim(trim($value), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException(
                "inline theme field '{$key}' is not a #RRGGBB colour: '" . $value . "'",
            );
        }

        return (int) hexdec($hex);
    }
}
