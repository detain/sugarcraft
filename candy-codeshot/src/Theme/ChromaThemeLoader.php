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

    /** Maps chroma token names to Theme property names. */
    private const TOKEN_MAP = [
        'comment'          => 'lineNumber',
        'keyword'          => 'windowRed',
        'string'           => 'windowGreen',
        'number'           => 'windowYellow',
        'variable'         => 'foreground',
        'constant'         => 'windowRed',
        'operator'         => 'foreground',
        'type'             => 'windowYellow',
        'class'            => 'windowYellow',
        'function'         => 'windowGreen',
        'punctuation'      => 'foreground',
        'attribute'        => 'windowYellow',
        'tag'              => 'windowRed',
        'error'            => 'windowRed',
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

        $windowRed    = $propertyMap['windowRed']    ?? '#ff5f56';
        $windowYellow = $propertyMap['windowYellow'] ?? '#ffbd2e';
        $windowGreen  = $propertyMap['windowGreen']  ?? '#27c93f';

        $border = self::coerceColor($data['border'] ?? $data['editorBackgroundColor'] ?? null)
            ?? '#30363d';

        $lineNumber = $propertyMap['lineNumber']
            ?? self::coerceColor($data['lineNumberColor'] ?? null)
            ?? '#6e7681';

        $fontFamily = $data['fontFamily'] ?? 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';

        return new Theme(
            background:     $background,
            foreground:    $foreground,
            border:        $border,
            shadow:        'rgba(0, 0, 0, 0.5)',
            lineNumber:    $lineNumber,
            windowRed:      $windowRed,
            windowYellow:   $windowYellow,
            windowGreen:   $windowGreen,
            fontFamily:    $fontFamily,
            fontSize:      14.0,
            lineHeight:    1.4,
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
