<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Support;

use SugarCraft\Core\Util\Sanitize;

/**
 * Trust boundary for every string this component can echo to a terminal.
 *
 * QuickPick renders data it does not own: item values from the caller's
 * corpus (or read back from disk by FileHistory), the filter text typed at
 * runtime, the prompt, help-bar legends, and status-bar messages. Any of
 * these can carry terminal control bytes — ESC/CSI/OSC-8 hyperlinks, OSC 0
 * window-title writes, DCS/SOS/PM/APC payloads, 7- and 8-bit C1, NUL/BEL,
 * forged U+E000/U+E001 mouse-zone sentinels, bidi-override characters such
 * as U+202E, or undecodable UTF-8. Left raw, one of those strings reaching
 * a real terminal is an escape-injection primitive.
 *
 * This is the single guard every ingress passes through:
 *
 *  1. `Sanitize::untrustedForMarkedFrames()` deletes every escape-introduced
 *     sequence (both spellings), C0 controls except TAB/LF/CR, DEL, 8-bit
 *     C1, and the U+E000/U+E001 zone sentinels — the same sweep sugar-table
 *     applies at its cell ingress (ce84fb91a) and sugar-widgets at its Tabs
 *     ingress (5979d4644).
 *  2. Invalid UTF-8 is repaired to U+FFFD *before* the Unicode-aware passes,
 *     so a malformed byte can never make a `/u` pattern bail and let the
 *     rest of a hostile string through.
 *  3. `Sanitize::markInvisibleFormatting()` turns bidi overrides and
 *     zero-width characters into printable ASCII markers, so a value can't
 *     make the frame display text the bytes never contained.
 *  4. Finally TAB/LF/CR are flattened to spaces: every overlay line is a
 *     single fixed-width row, so a surviving newline or tab (both tolerated
 *     by step 1 because other surfaces use them) would desynchronise the
 *     composite regardless of who injected it.
 *
 * The guard is idempotent and byte-identical on ordinary printable strings,
 * so hosts that pass clean data (and hosts that style the result afterwards
 * via setItemFormatter — their SGR is applied AFTER this guard and survives)
 * see no change.
 */
final class UntrustedText
{
    /**
     * Make `$value` safe to render inside the fixed-width overlay frame.
     */
    public static function guard(string $value): string
    {
        $value = self::repairUtf8($value);
        $value = Sanitize::untrustedForMarkedFrames($value);
        $value = Sanitize::markInvisibleFormatting($value);

        return \str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
    }

    /**
     * Replace malformed UTF-8 with U+FFFD without relying on the process
     * default substitution mode (mirrors Sanitize's private repair).
     */
    private static function repairUtf8(string $value): string
    {
        $previous = \mb_substitute_character();
        \mb_substitute_character(0xFFFD);
        $repaired = \mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        \mb_substitute_character($previous);

        return $repaired;
    }
}
