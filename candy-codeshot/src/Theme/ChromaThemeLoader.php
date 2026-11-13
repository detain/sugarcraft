<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Theme;

use SugarCraft\CodeShot\Theme;

/**
 * Loads a chroma JSON theme file and maps its token colors to a
 * {@see Theme} that can be used by {@see \SugarCraft\CodeShot\SvgRenderer}.
 *
 * Chroma themes use a simpler flat structure than VS Code themes:
 * top-level keys for "background", "foreground", "cursor", and a
 * "colors" object for syntax tokens (e.g., {"comment": "#6A9955"}).
 *
 * @see https://github.com/charmbracelet/chroma/tree/master/xsl
 */
final class ChromaThemeLoader
{
    use NormalizesHexColor;

    /**
     * Maps chroma token names to Theme syntax-token slots (cs-07) — never
     * to chrome colours: a `keyword` token tinting the traffic lights was
     * the same mis-mapping the VS Code loader carried.
     *
     * A chroma JSON `colors` object has no cascade order (each key is
     * unique), so where several names fold onto one slot the entry listed
     * FIRST wins — the list order is the precedence, most-specific first
     * (`type` over `class`/`attribute`).
     *
     * 'variable'/'operator'/'punctuation' are deliberately absent: the old
     * map folded them onto 'foreground', which the builder ignored anyway,
     * so code text keeps the theme's base foreground for those classes.
     */
    private const TOKEN_MAP = [
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
    ];

    /**
     * Load a Theme from a chroma JSON theme file at `$path`.
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
            throw new \InvalidArgumentException("Chroma theme file not found: {$path}");
        }

        $json = file_get_contents($path, false, null, 0, 1_000_000);
        if ($json === false) {
            throw new \InvalidArgumentException("Failed to read chroma theme file: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * Build a Theme from an already-decoded chroma theme array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): Theme
    {
        $colors = $data['colors'] ?? [];

        $background = self::coerceColor($data['background'] ?? null) ?? '#0d1117';
        $foreground = self::coerceColor($data['foreground'] ?? null) ?? '#c9d1d9';

        $propertyMap = [];
        foreach (self::TOKEN_MAP as $token => $prop) {
            if (isset($colors[$token]) && is_string($colors[$token]) && !isset($propertyMap[$prop])) {
                $normalized = self::normalizeHex($colors[$token]);
                if (self::isValidHexColor($normalized)) {
                    $propertyMap[$prop] = $normalized;
                }
            }
        }

        $windowRed    = '#ff5f56';
        $windowYellow = '#ffbd2e';
        $windowGreen  = '#27c93f';

        $border = self::coerceColor($data['border'] ?? $data['editorBackgroundColor'] ?? null)
            ?? '#30363d';

        // Chrome from UI keys only (cs-07); the comment token stays a gutter
        // FALLBACK for themes carrying no lineNumberColor, mirroring the VS
        // Code loader. Previously the token overrode even an explicit UI key.
        $lineNumber = self::coerceColor($data['lineNumberColor'] ?? null)
            ?? $propertyMap['comment']
            ?? '#6e7681';

        // A hostile theme can carry fontFamily as a list or a number, and the
        // sanitizer's typed string parameter would fatal on it — theme files
        // are not trusted, so anything that is not a plain string falls back
        // to the house default instead of reaching the sanitizer or the wire.
        $fontFamily = $data['fontFamily'] ?? null;
        if (!is_string($fontFamily)) {
            $fontFamily = 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';
        }

        return new Theme(
            background:     $background,
            foreground:    $foreground,
            border:        $border,
            shadow:        'rgba(0, 0, 0, 0.5)',
            lineNumber:    $lineNumber,
            windowRed:      $windowRed,
            windowYellow:   $windowYellow,
            windowGreen:   $windowGreen,
            fontFamily:    self::sanitizeFontFamily($fontFamily),
            fontSize:      14.0,
            lineHeight:    1.4,
            keyword:       $propertyMap['keyword'] ?? null,
            string:        $propertyMap['string'] ?? null,
            number:        $propertyMap['number'] ?? null,
            comment:       $propertyMap['comment'] ?? null,
            type:          $propertyMap['type'] ?? null,
        );
    }

    /**
     * Return a valid 6-digit hex colour string or null.
     *
     * The guard checks the normalised value honestly: normalizeHex always
     * prefixes '#', so the old `str_starts_with(..., '#')` test was
     * tautological and let CSS names and junk through into renderers.
     */
    private static function coerceColor(mixed $value): ?string
    {
        if ($value === null || !is_string($value)) {
            return null;
        }
        $normalized = self::normalizeHex($value);
        return self::isValidHexColor($normalized) ? $normalized : null;
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
