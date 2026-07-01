<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * C0 control character sanitization for TUI components.
 *
 * Terminal control sequence injection is a real TUI attack vector.
 * This class provides a single point for security auditing.
 * SGR escape sequences (\x1b[...) are preserved.
 *
 * Mirrors charmbracelet/<repo>.sanitize helpers.
 */
final class Sanitize
{
    /**
     * Strip C0 control characters from caller-supplied text so they
     * cannot inject newlines or corrupt the TUI render.
     * \n \r \t are replaced with spaces; other C0 (\x00-\x08\x0b\x0c\x0e-\x1f)
     * are removed. ESC (\x1b) is preserved for SGR sequences.
     */
    public static function controlChars(string $s): string
    {
        $s = str_replace(["\n", "\r", "\t"], ' ', $s);
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s) ?? $s;
    }
}
