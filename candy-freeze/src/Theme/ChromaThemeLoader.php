<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Theme;

use SugarCraft\Freeze\Theme;
use SugarCraft\Freeze\WindowStyle;

/**
 * Loads a chroma JSON theme file and maps its token colors to a
 * {@see Theme} that can be used by {@see \SugarCraft\Freeze\SvgRenderer}.
 *
 * Chroma themes use a simpler flat structure than VS Code themes:
 * top-level keys for "background", "foreground", "cursor", and a
 * "colors" object for syntax tokens (e.g., {"comment": "#6A9955"}).
 *
 * @see https://github.com/charmbracelet/chroma/tree/master/xsl
 */
final class ChromaThemeLoader
{
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
     * @throws \InvalidArgumentException if the file does not exist or is not valid JSON.
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
            if (isset($colors[$token]) && !isset($propertyMap[$prop])) {
                $propertyMap[$prop] = self::normalizeHex($colors[$token]);
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
            windowStyle:   WindowStyle::Macos,
        );
    }

    /**
     * Return a valid 6-digit hex colour string or null.
     */
    private static function coerceColor(mixed $value): ?string
    {
        if ($value === null || !is_string($value)) {
            return null;
        }
        $normalized = self::normalizeHex($value);
        return str_starts_with($normalized, '#') ? $normalized : null;
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
