<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Theme;

use SugarCraft\Freeze\Theme;
use SugarCraft\Freeze\WindowStyle;

/**
 * Loads a VS Code JSON theme file and maps its token colors to a
 * {@see Theme} that can be used by {@see \SugarCraft\Freeze\SvgRenderer}.
 *
 * VS Code themes use a flat JSON structure with a "colors" object for
 * UI elements and a "tokenColors" array for syntax highlighting. Scope
 * names follow the TextMate scope convention (e.g., "comment",
 * "keyword", "string", "variable", "constant").
 *
 * @see https://code.visualstudio.com/api/references/theme-color
 * @see https://code.visualstudio.com/api/language-extensions/syntax-highlight-guide
 */
final class VsCodeThemeLoader
{
    /** Maps TextMate scope roots to Theme property names. */
    private const SCOPE_MAP = [
        'comment'          => 'lineNumber',
        'keyword'          => 'windowRed',
        'string'           => 'windowGreen',
        'number'           => 'windowYellow',
        'constant'         => 'windowRed',
        'type'             => 'windowYellow',
        'class'            => 'windowYellow',
        'function'         => 'windowGreen',
        // 'variable', 'operator', 'entity' are not in SCOPE_MAP so they fall
        // through to the foreground fallback. This preserves editor.foreground
        // as the base text colour and lets syntax colours tint specific props.
    ];

    /**
     * Load a Theme from a VS Code JSON theme file at `$path`.
     *
     * @throws \InvalidArgumentException if the file does not exist or is not valid JSON.
     */
    public static function load(string $path): Theme
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("VS Code theme file not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException("Failed to read VS Code theme file: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * Build a Theme from an already-decoded VS Code theme array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): Theme
    {
        $colors = $data['colors'] ?? [];
        $tokenColors = $data['tokenColors'] ?? [];

        $background = self::resolveColor($colors, [
            'editor.background',
            'editor.background',
        ], '#0d1117');

        $foreground = self::resolveColor($colors, [
            'editor.foreground',
            'editor.foreground',
        ], '#c9d1d9');

        $border = self::resolveColor($colors, [
            'editorLineNumber.foreground',
            'editorLineNumber.activeForeground',
            'focusBorder',
        ], '#30363d');

        $lineNumber = self::resolveColor($colors, [
            'editorLineNumber.foreground',
        ], '#6e7681');

        // Map token colors to a flat property map for Theme construction.
        // Note: foreground is NOT overridden by tokenColors — editor.foreground
        // in the colors array is the authoritative base text colour.
        $propertyMap = self::buildPropertyMap($tokenColors);

        // Override UI colours with token-derived values where available.
        // lineNumber is commonly derived from comment token color.
        $lineNumber  = $propertyMap['lineNumber']  ?? $lineNumber;

        $fontFamily = $propertyMap['fontFamily']
            ?? 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';

        $windowRed    = $propertyMap['windowRed']    ?? '#ff5f56';
        $windowYellow = $propertyMap['windowYellow'] ?? '#ffbd2e';
        $windowGreen  = $propertyMap['windowGreen']  ?? '#27c93f';

        return new Theme(
            background:     $background,
            foreground:    $foreground,
            border:         $border,
            shadow:         'rgba(0, 0, 0, 0.5)',
            lineNumber:     $lineNumber,
            windowRed:      $windowRed,
            windowYellow:   $windowYellow,
            windowGreen:    $windowGreen,
            fontFamily:     $fontFamily,
            fontSize:       14.0,
            lineHeight:     1.4,
            windowStyle:    WindowStyle::Macos,
        );
    }

    /**
     * Extract a colour value from the colours map, trying multiple keys.
     *
     * @param array<string, string> $colors
     * @param list<string> $keys
     */
    private static function resolveColor(array $colors, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (isset($colors[$key]) && is_string($colors[$key])) {
                return self::normalizeHex($colors[$key]);
            }
        }
        return $fallback;
    }

    /**
     * Walk tokenColors entries and build a flat property map from
     * TextMate scope roots to color values.
     *
     * Foreground is NOT mapped from tokenColors — editor.foreground in
     * the colors array is the authoritative base text colour.
     *
     * @param list<array<string, mixed>> $tokenColors
     * @return array<string, string>
     */
    private static function buildPropertyMap(array $tokenColors): array
    {
        $map = [];
        foreach ($tokenColors as $entry) {
            $scope = $entry['scope'] ?? null;
            $settings = $entry['settings'] ?? [];

            if ($scope === null || $settings === []) {
                continue;
            }

            $scopes = is_array($scope) ? $scope : [$scope];
            $foreground = $settings['foreground'] ?? null;

            if ($foreground !== null) {
                $hex = self::normalizeHex($foreground);
                foreach ($scopes as $s) {
                    $root = self::scopeRoot($s);
                    if (isset(self::SCOPE_MAP[$root])) {
                        $prop = self::SCOPE_MAP[$root];
                        if (!isset($map[$prop])) {
                            $map[$prop] = $hex;
                        }
                    }
                    // Note: we intentionally do NOT fall through to set 'foreground'
                    // from tokenColors — editor.foreground is the base text colour.
                }
            }

            if (isset($settings['fontFamily'])) {
                $map['fontFamily'] = (string) $settings['fontFamily'];
            }
        }

        return $map;
    }

    /**
     * Return the first dot-separated component of a TextMate scope.
     *
     * Example: "punctuation.definition.tag" → "punctuation"
     */
    private static function scopeRoot(string $scope): string
    {
        $dot = strpos($scope, '.');
        return $dot !== false ? substr($scope, 0, $dot) : $scope;
    }

    /**
     * Normalize a hex colour to 6-digit lowercase #rrggbb.
     *
     * Handles 3-digit #rgb and 8-digit #rrggbbaa formats.
     */
    private static function normalizeHex(string $hex): string
    {
        $hex = trim($hex);
        if (str_starts_with($hex, '#')) {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }
        return '#' . strtolower($hex);
    }
}
