<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Sanitize as CoreSanitize;

/**
 * Terminal output sanitizer — strips dangerous control bytes and escape
 * sequences from untrusted strings before they reach the terminal.
 *
 * This class is the single authoritative sink for sanitizing module output.
 * Callers that need to preserve color (SGR sequences) must NOT use this;
 * module sinks render plain text so full strip is correct.
 *
 * Mirrors the lattice output sanitization pattern.
 */
final class Sanitize
{
    /**
     * Strip all C0 control bytes (\x00–\x1f) and C1 (\x80–\x9f)
     * except \n (\x0a) and \t (\x09), and remove every escape sequence
     * (CSI/OSC/SGR) introduced by \x1b.
     *
     * Use this on any string that originates from an external process,
     * network response, or user-controlled source before writing to the
     * terminal.
     *
     * Delegates to {@see \SugarCraft\Core\Util\Sanitize::untrusted()}, the
     * canonical single source of truth in candy-core. Output is byte-identical;
     * this wrapper only preserves the historical SugarCraft\Dash\Output\Sanitize
     * call site.
     *
     * @param string $s Untrusted input string
     * @return string Sanitized string safe for terminal output
     */
    public static function untrusted(string $s): string
    {
        return CoreSanitize::untrusted($s);
    }
}
