<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Highlighter;

/**
 * Pluggable syntax highlighter interface.
 * Implementations return ANSI-escaped strings with SGR codes for syntax coloring.
 */
interface HighlighterInterface
{
    /**
     * Highlight $code in the given $language.
     * Returns an ANSI string with SGR codes for syntax coloring.
     *
     * @param string $code      The source code to highlight
     * @param string $language The language identifier (e.g. 'php', 'javascript')
     * @return string ANSI-escaped highlighted string
     */
    public function highlight(string $code, string $language): string;

    /**
     * Check if this highlighter supports the given language.
     */
    public function supports(string $language): bool;
}
