<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

/**
 * Reader for btop's `.theme` format: `theme[key]="value"` lines with `#`
 * comment lines. Produces the raw key → value map that
 * {@see ThemeConfig::fromSource()} resolves; no color parsing happens here,
 * because btop keeps the raw string to tell "empty" (transparent / no mid)
 * apart from "absent" (fall back to Default).
 *
 * Mirrors aristocratos/btop Theme::loadFile (src/btop_theme.cpp).
 *
 * Deviations, all line-local where btop reads a character stream:
 * - btop skips a comment only when `#` is the first byte right after the
 *   previous entry, so a commented-out `#theme[x]="…"` that follows a blank
 *   line is re-read as a live entry. Here any line whose first non-blank
 *   character is `#` is a comment. Of the 41 shipped themes only gotham has
 *   such a line, and its next line re-sets the same key, so every shipped
 *   theme resolves identically.
 * - An unquoted value has trailing whitespace (incl. a CRLF `\r`) trimmed;
 *   btop would keep it and then reject the hex.
 * - A quoted value never spans lines (btop reads to the next `"` anywhere).
 */
final class ThemeFile
{
    private function __construct()
    {
    }

    /**
     * Parse `.theme` text. Keys outside the btop vocabulary are dropped (btop
     * ignores them); a key given twice keeps its last value.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $open = strpos($line, '[');
            if ($open === false) {
                continue;
            }
            $close = strpos($line, ']', $open + 1);
            if ($close === false) {
                continue;
            }
            $name = substr($line, $open + 1, $close - $open - 1);
            if (!array_key_exists($name, ThemeConfig::DEFAULT_THEME)) {
                continue;
            }
            $eq = strpos($line, '=', $close + 1);
            if ($eq === false) {
                continue;
            }
            $value = ltrim(substr($line, $eq + 1));
            if ($value !== '' && $value[0] === '"') {
                $end = strpos($value, '"', 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            } else {
                $value = rtrim($value);
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Read and parse a `.theme` file.
     *
     * btop's loadFile silently substitutes the Default theme for a missing or
     * unreadable file. Here that substitution lives one level up, in
     * {@see ThemeRegistry::load()}, so a direct read of a bad path is an error
     * the caller can see.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException when the file cannot be read
     */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Theme file "%s" is not readable', $path));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Theme file "%s" is not readable', $path));
        }
        return self::parse($contents);
    }
}
