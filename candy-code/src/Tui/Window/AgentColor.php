<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\Color;

/**
 * The agent-preset `color:` vocabulary resolved to a paintable Color.
 *
 * Presets and skill frontmatter name colours in two shapes: a small word set
 * (red/blue/green/yellow/purple/orange/pink/cyan), and a literal `#rrggbb`.
 * The words are theme tokens — they ride whatever palette the user chose,
 * mapped onto the closest shell hue: error/info/success/warning carry
 * red/blue/green/yellow outright, and shellPrimary/assistantLabel/userLabel/systemLabel
 * stand in for purple/pink/orange/cyan, the four with no token of their own.
 * A hex is the user overriding the palette on purpose, and it passes only
 * after the shell's own contrast law ({@see Theme::CONTRAST_MIN}) finds it
 * distinct from {@see Theme::$shellForeground} — the colour the tinted label
 * competes with, so a hex that sits within it would paint an agent name
 * invisible on the strip. Anything else — a word outside the eight, a
 * malformed hex, a 3-digit shorthand — answers null, and null means "use
 * the default colour", never a guess.
 */
final class AgentColor
{
    /** The eight word-colours the preset vocabulary allows, in name order. */
    public const VOCABULARY = ['blue', 'cyan', 'green', 'orange', 'pink', 'purple', 'red', 'yellow'];

    private function __construct()
    {
    }

    /**
     * Resolve $vocab (a vocabulary word or a `#rrggbb` literal) against $theme.
     *
     * @return ?Color a paintable colour (a word arrives as its theme token's
     *                current value passed through the hex round-trip, re-read
     *                per call so a `/theme` switch lands on the next frame),
     *                or null when the input names no legible colour
     */
    public static function resolve(?string $vocab, Theme $theme): ?Color
    {
        if ($vocab === null || $vocab === '') {
            return null;
        }

        // Config-sourced colour text arrives whitespace-padded from YAML
        // and hand-edited presets; padding is not a different colour.
        $vocab = trim($vocab);
        if ($vocab === '') {
            return null;
        }

        $token = self::tokenFor(strtolower($vocab), $theme);
        $hex = $token?->toHex() ?? self::legibleHex($vocab);
        if ($hex === null) {
            return null;
        }

        // The roster's single construction site: this is where the window kit's
        // preset hex strings become Colors, and consumers never re-derive one.
        $colour = Color::hex($hex);
        // The contrast floor guards the literal path only — a theme token is
        // legible on the shell by the theme's own construction, and rounding
        // it through hex and back must not then fail its own floor.
        if ($token === null && Theme::contrast($colour, $theme->shellForeground) < Theme::CONTRAST_MIN) {
            return null;
        }

        return $colour;
    }

    /** The theme token a vocabulary word names, or null for anything else. */
    private static function tokenFor(string $word, Theme $theme): ?Color
    {
        return match ($word) {
            'red' => $theme->shellError,
            'blue' => $theme->shellInfo,
            'green' => $theme->shellSuccess,
            'yellow' => $theme->shellWarning,
            'purple' => $theme->shellPrimary,
            'orange' => $theme->userLabel,
            'pink' => $theme->assistantLabel,
            'cyan' => $theme->systemLabel,
            default => null,
        };
    }

    /** A `#rrggbb` literal shape-validated and lower-cased, or null. */
    private static function legibleHex(string $candidate): ?string
    {
        // Validate by walk rather than preg: a bracket-class pattern is a
        // glob-shaped literal for the tree's corpus figures.
        if (strlen($candidate) !== 7 || $candidate[0] !== '#') {
            return null;
        }
        if (strspn(substr($candidate, 1), '0123456789abcdefABCDEF') !== 6) {
            return null;
        }

        return strtolower($candidate);
    }
}
