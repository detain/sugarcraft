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

    /**
     * Maps TextMate scope roots to Theme syntax-token slots (cs-07).
     *
     * The tokenColors array cascades last-wins (see buildPropertyMap),
     * which is VS Code's own documented precedence, so a theme repeating
     * or broadening a scope overrides itself as the editor would. Nothing
     * maps to the window/chrome colours any more: a `keyword` scope
     * tinting the traffic lights was the bug this split closes.
     */
    private const SCOPE_MAP = [
        'comment'          => 'comment',
        'keyword'          => 'keyword',
        'string'           => 'string',
        'number'           => 'number',
        'constant'         => 'number',
        'type'             => 'type',
        'class'            => 'type',
        'function'         => 'type',
        'attribute'        => 'type',
        'tag'              => 'keyword',
        'error'            => 'keyword',
        // 'variable', 'operator', 'entity', 'punctuation' are not in SCOPE_MAP
        // so they fall through to the foreground fallback. This preserves
        // editor.foreground as the base text colour.
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
        // cs-11 (FUZZ-03): a VS Code theme is third-party JSON and scalar
        // shapes were live TypeErrors past the documented @throws —
        // {"colors":42} crashed resolveColor's array parameter. Mirror the
        // Chroma loader's total-function behaviour: a section that is not
        // an array carries no colours, and the documented fallbacks answer.
        $colors = is_array($data['colors'] ?? null) ? $data['colors'] : [];
        $tokenColors = is_array($data['tokenColors'] ?? null) ? $data['tokenColors'] : [];

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

        // Token scopes now feed only the syntax slots; chrome comes from UI
        // keys alone (cs-07). The gutter still backs off to the theme's
        // comment colour when the theme carries no editorLineNumber key —
        // reading chrome and code in one hue is the older house default, and
        // a UI key always wins over that fallback.
        $propertyMap = self::buildPropertyMap($tokenColors);

        $lineNumber = self::resolveColor($colors, [
            'editorLineNumber.foreground',
        ], $propertyMap['comment'] ?? '#6e7681');

        $fontFamily = $propertyMap['fontFamily']
            ?? 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';

        return new Theme(
            background:     $background,
            foreground:    $foreground,
            border:         $border,
            shadow:         'rgba(0, 0, 0, 0.5)',
            lineNumber:     $lineNumber,
            windowRed:      '#ff5f56',
            windowYellow:   '#ffbd2e',
            windowGreen:    '#27c93f',
            fontFamily:     self::sanitizeFontFamily($fontFamily),
            fontSize:       14.0,
            lineHeight:     1.4,
            keyword:        $propertyMap['keyword'] ?? null,
            string:         $propertyMap['string'] ?? null,
            number:         $propertyMap['number'] ?? null,
            comment:        $propertyMap['comment'] ?? null,
            type:           $propertyMap['type'] ?? null,
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
     * TextMate scope roots to syntax-token colour values.
     *
     * Later entries win: VS Code applies tokenColors top-to-bottom, so a
     * theme that repeats or broadens a scope overrides its earlier value.
     * (The pre-cs-07 first-wins flip inverted that cascade; the traffic
     * lights tinting from `keyword` was the same mis-mapping's other face.)
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
            // cs-11: tokenColors entries are third-party too — a scalar
            // element would fatal on $entry['scope'].
            if (!is_array($entry)) {
                continue;
            }

            $scope = $entry['scope'] ?? null;
            $settings = $entry['settings'] ?? [];

            if ($scope === null || !is_array($settings) || $settings === []) {
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
                    if (!is_string($s)) {
                        // cs-11: a scalar inside a scope array (e.g. scope:[42]).
                        continue;
                    }
                    $root = self::scopeRoot($s);
                    if (isset(self::SCOPE_MAP[$root])) {
                        $map[self::SCOPE_MAP[$root]] = $hex;
                    }
                    // Note: we intentionally do NOT fall through to set 'foreground'
                    // from tokenColors — editor.foreground is the base text colour.
                }
            }

            // Only a real string survives: (string)-casting an array or
            // object produced "Array" (with a notice), which the whitelist
            // accepted cleanly and silently emitted a junk font family.
            if (isset($settings['fontFamily']) && is_string($settings['fontFamily'])) {
                $map['fontFamily'] = $settings['fontFamily'];
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
     * Font family names are echoed into SVG attributes and CSS by the
     * renderers; keep them to a plainly-safe charset and fall back to the
     * house default instead of trusting theme files (docs-fix era).
     */
    private static function sanitizeFontFamily(string $family): string
    {
        $p = '/^[A-Za-z0-9 ,"\'\-]+$/';
        if (preg_match($p, $family) !== 1) {
            return 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';
        }

        return $family;
    }
}
