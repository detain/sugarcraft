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
     * Bound on the guard() memo (-072): the same corpus strings are guarded
     * per visible row per frame and again in the width pass; overwhelmingly
     * clean input is byte-identical output, so the full transform runs at
     * most once per DISTINCT input. Oldest entry drops on overflow.
     */
    private const GUARD_CACHE_LIMIT = 4096;

    /** @var array<string, string> exact input => guarded bytes */
    private static array $memo = [];

    /**
     * Transform-pass counter: the memo's whole purpose is "one sweep per
     * distinct input", and a bounded table cannot prove that (a recompute
     * overwrites its own slot). The test pin reads this seam by reflection.
     */
    private static int $transformPasses = 0;

    /**
     * Make `$value` safe to render inside the fixed-width overlay frame.
     *
     * Fast path first (a printable-ASCII-only string provably passes all
     * four steps untouched — no regexes, no substitute-character dance),
     * memo keyed by the exact input behind it. The slow path is the full
     * documented sweep; hoisting the `mb_substitute_character` triple out
     * of it as a permanent class-init side effect was rejected on purpose:
     * that mutates a PROCESS-GLOBAL for the whole host. Instead the memo
     * guarantees the triple runs at most once per distinct dirty input.
     */
    public static function guard(string $value): string
    {
        // A guarded result can itself be the empty string (everything was
        // deleted), so `?? null` would miss the memo forever for those keys.
        if (\array_key_exists($value, self::$memo)) {
            return self::$memo[$value];
        }

        // ASCII \x20..\x7E cannot be touched by any step: valid UTF-8 (the
        // repair is an identity), outside every control/C1/sentinel/invisible
        // class the two Sanitize sweeps delete or mark, and carries no
        // TAB/LF/CR to flatten.
        if ($value !== '' && \preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            $result = $value;
        } else {
            $result = self::transform($value);
        }

        if (\count(self::$memo) >= self::GUARD_CACHE_LIMIT) {
            unset(self::$memo[\array_key_first(self::$memo)]);
        }
        self::$memo[$value] = $result;

        return $result;
    }

    /** The full four-step sweep, run only for non-fast-path inputs. */
    private static function transform(string $value): string
    {
        self::$transformPasses++;
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
