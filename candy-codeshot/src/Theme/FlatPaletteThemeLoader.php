<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Theme;

use SugarCraft\CodeShot\Theme;

/**
 * Loads a flat JSON palette file and maps its token colours to a
 * {@see Theme} that can be used by {@see \SugarCraft\CodeShot\SvgRenderer}.
 *
 * The palette schema is this library's OWN: a flat JSON object with
 * top-level `background`, `foreground`, `border`, `lineNumberColor` and
 * `fontFamily` keys plus a `colors` object of lowercase syntax-token
 * colours (e.g., {"comment": "#6A9955"}). It is deliberately NOT a
 * `alecthomas/chroma` style-file export: chroma themes are Go structs
 * keyed PascalCase (`CommentPreproc`, `KeywordConstant`…) with bold/italic
 * fields this shape has no place for, so a real chroma export binds
 * nothing through the lowercase map below. The name and the former
 * "chroma JSON theme file" claim were inherited fiction (candy-codeshot-026).
 */
final class FlatPaletteThemeLoader
{
    use NormalizesHexColor;
    use SanitizesFontFamily;

    /**
     * Maps flat-palette token names to Theme syntax-token slots (cs-07) — never
     * to chrome colours: a `keyword` token tinting the traffic lights was
     * the same mis-mapping the VS Code loader carried.
     *
     * A flat palette's `colors` object has no cascade order (each key is
     * unique), so where several names fold onto one slot the entry listed
     * FIRST wins — the list order is the precedence, most-specific first
     * (`type` over `class`/`attribute`).
     *
     * 'variable'/'operator'/'punctuation' are deliberately absent: the old
     * map folded them onto 'foreground', which the builder ignored anyway,
     * so code text keeps the palette's base foreground for those classes.
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
     * Load a Theme from a flat JSON palette file at `$path`.
     *
     * Only a regular, readable file is opened: `file_exists` alone admits
     * FIFOs and sockets, whose untimed read would block forever (candy-codeshot-049).
     * The read carries a 10 s stream timeout — an honest backstop only for
     * stream-wrapper paths (a hung NFS regular file cannot be interrupted by
     * it, which is exactly why the is_file door above is the real defence) —
     * and is capped at 1 MB; a larger file is truncated, and the truncated
     * JSON then fails to parse (JsonException) rather than silently loading
     * half a palette.
     *
     * @throws \InvalidArgumentException if the path is not a regular readable file, or cannot be read.
     * @throws \JsonException if the (possibly truncated) contents are not valid JSON.
     */
    public static function load(string $path): Theme
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Flat palette theme file not found: {$path}");
        }

        if (!is_file($path)) {
            throw new \InvalidArgumentException("Flat palette theme path is not a regular file: {$path}");
        }

        if (!is_readable($path)) {
            throw new \InvalidArgumentException("Flat palette theme file is not readable: {$path}");
        }

        $json = file_get_contents(
            $path,
            false,
            stream_context_create(['options' => ['timeout' => 10]]),
            0,
            1_000_000,
        );
        if ($json === false) {
            throw new \InvalidArgumentException("Failed to read flat palette theme file: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * Build a Theme from an already-decoded flat palette array.
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

        // Border keys stay inside the flat-palette schema (cs-18): the former
        // `editorBackgroundColor` fallback was a VS Code key leaking into a
        // format that has no such thing — a genuine palette file never
        // carries it, and a VS Code file belongs to VsCodeThemeLoader.
        // Each candidate is coerced on its own merit (candy-codeshot-054):
        // the old `$data['border'] ?? $data['background']` chain keyed on
        // isset, so a present-but-invalid "border":"red" null-coalesced to
        // the next key as if absent — but coerceColor had already decided,
        // and the jump skipped a perfectly good background fallback.
        $border = self::coerceColor($data['border'] ?? null)
            ?? self::coerceColor($data['background'] ?? null)
            ?? '#30363d';

        // Chrome from UI keys only (cs-07); the comment token stays a gutter
        // FALLBACK for palettes carrying no lineNumberColor, mirroring the VS
        // Code loader. Previously the token overrode even an explicit UI key.
        $lineNumber = self::coerceColor($data['lineNumberColor'] ?? null)
            ?? $propertyMap['comment']
            ?? '#6e7681';

        // A hostile palette can carry fontFamily as a list or a number, and the
        // sanitizer's typed string parameter would fatal on it — theme files
        // are not trusted, so anything that is not a plain string falls back
        // to the house default instead of reaching the sanitizer or the wire.
        $fontFamily = $data['fontFamily'] ?? null;
        if (!is_string($fontFamily)) {
            $fontFamily = self::HOUSE_FONT_FAMILY;
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
}
