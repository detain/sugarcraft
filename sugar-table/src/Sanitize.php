<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Sanitize cell and header values before they reach the terminal buffer.
 *
 * Prevents ANSI injection attacks (CSI/OSC escape sequences embedded in data)
 * and other control characters from being interpreted as terminal commands.
 *
 * This is a thin delegator onto the canonical policy in
 * {@see \SugarCraft\Core\Util\Sanitize::cellValue()} — the single source of
 * truth shared with the candy-query grid. Delegating converges sugar-table
 * onto that one behavior: newlines collapse to ↵ (U+21B5), TAB and every other
 * control byte become · (U+00B7), and invalid UTF-8 is repaired to U+FFFD
 * rather than silently dropped.
 */
final class Sanitize
{
    /**
     * Sanitize a string value for safe terminal rendering.
     *
     * @param string $s                The raw string to sanitize
     * @param bool   $preserveNewlines When true, preserve \n (for multiline expand
     *                                  paths that explode on "\n"); when false,
     *                                  collapse all newline variants to ↵ (U+21B5)
     * @return string Sanitized string safe for the terminal
     */
    public static function value(string $s, bool $preserveNewlines = false): string
    {
        return \SugarCraft\Core\Util\Sanitize::cellValue($s, $preserveNewlines);
    }
}
