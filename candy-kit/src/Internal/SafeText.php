<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Internal;

use SugarCraft\Core\Util\Ansi;

/**
 * Neutralize terminal-control injection in caller-supplied display text.
 *
 * The single-line presenter primitives (StatusLine, Stage, Section, HelpText)
 * interpolate caller strings straight into ANSI output that a frame-diff
 * renderer (candy-core) paints onto a screen it owns. An embedded cursor move,
 * screen clear, OSC 52 clipboard write, DCS/APC payload, or a raw newline in
 * that text would desync the renderer's one-line-per-row model or drive a
 * terminal-escape injection — so it must be stripped before it reaches the
 * terminal (only Frame previously bounded caller text, and only by width).
 *
 * @internal Not part of the public API; may change without notice.
 */
final class SafeText
{
    /**
     * Strip escape sequences and control bytes from a one-line display string.
     *
     * {@see Ansi::strip()} removes every escape sequence in 7-bit and 8-bit
     * form — CSI / OSC / DCS / SOS / PM / APC payloads, string-terminator
     * controls, and lone C1 bytes; the second pass drops the remaining C0
     * control bytes (0x00-0x1f) and DEL (0x7f). Multi-byte UTF-8 survives
     * untouched (its C1-range continuation bytes sit inside fully
     * well-formed sequences), and clean
     * printable text is returned identical.
     */
    public static function line(string $s): string
    {
        return preg_replace('/[\x00-\x1f\x7f]/', '', Ansi::strip($s)) ?? '';
    }
}
