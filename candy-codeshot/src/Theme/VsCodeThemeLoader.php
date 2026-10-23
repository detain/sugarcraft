<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Theme;

use SugarCraft\CodeShot\Theme;

/**
 * Loads a VS Code JSON theme file and maps its token colors to a
 * {@see Theme} that can be used by {@see \SugarCraft\CodeShot\SvgRenderer}.
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
    use NormalizesHexColor;

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
     * The file is read up to 1 MB; a larger file is truncated, and the
     * truncated JSON then fails to parse (JsonException) rather than
     * silently loading half a theme.
     *
     * @throws \InvalidArgumentException if the file does not exist or cannot be read.
     * @throws \JsonException if the (possibly truncated) contents are not valid JSON.
     */
    public static function load(string $path): Theme
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("VS Code theme file not found: {$path}");
        }

        $json = file_get_contents($path, false, null, 0, 1_000_000);
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
        ], '#0d1117');

        $foreground = self::resolveColor($colors, [
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
        );
    }

    /**
     * Extract a colour value from the colours map, trying multiple keys.
     * Values that are not 6-digit hex after normalisation are treated as
     * absent — the next key (or the fallback) answers instead.
     *
     * @param array<string, string> $colors
     * @param list<string> $keys
     */
    private static function resolveColor(array $colors, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (isset($colors[$key]) && is_string($colors[$key])) {
                $normalized = self::normalizeHex($colors[$key]);
                if (self::isValidHexColor($normalized)) {
                    return $normalized;
                }
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

            if ($foreground !== null && is_string($foreground)) {
                $hex = self::normalizeHex($foreground);
                if (!self::isValidHexColor($hex)) {
                    continue;
                }
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
}
