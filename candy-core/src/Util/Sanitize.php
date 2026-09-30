<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Canonical text sanitizer for TUI components — the single source of truth
 * for neutralizing terminal-control-sequence injection across SugarCraft.
 *
 * Terminal control sequence injection is a real TUI attack vector: a raw
 * ESC (0x1b) desyncs the frame-diff renderer's line model, NUL/control
 * bytes garble the terminal, and BEL makes it beep on every repaint. This
 * class is the one place to audit that risk. Three policies are offered,
 * differing in how much they preserve:
 *
 *   - {@see controlChars()} — strips C0 controls (ESC included) and maps
 *     \n \r \t to spaces; the printable SGR parameter text is left behind.
 *   - {@see cellValue()}    — glyph-replacement + UTF-8 repair for data grids.
 *   - {@see untrusted()}    — full ANSI strip for plain-text module sinks.
 *   - {@see untrustedForMarkedFrames()} — {@see untrusted()} plus the zone
 *     sentinel strip, for text destined for a frame that carries candy-mouse
 *     zone markup. The Private-Use codepoints it removes are the reason a
 *     separate policy is needed: see the reservation note below.
 *
 * **The Private-Use reservation.** A TUI frame is not only text: candy-mouse's
 * `Mark`/`Scan` delimit clickable zones with the sentinel pair U+E000 (open) and
 * U+E001 (close), and {@see \SugarCraft\Core\ImageOverlay} allocates its image
 * marker cells from the same block head (U+E000 + id). Those codepoints are
 * ordinary, well-formed 3-byte UTF-8, so {@see untrusted()} — whose vocabulary
 * is escapes, C0/C1 and DEL — passes hostile input carrying them straight
 * through. Text that reaches a zone-scanned frame therefore needs the sentinel
 * sweep of {@see stripZoneSentinels()} on top, or a model reply can forge zone
 * markup (attacker-chosen click targets) or break the zone parse outright.
 *
 * No single upstream counterpart is cited here because this class is a
 * SugarCraft-original assembled from three separate internal predecessors, not a
 * port (candy-core's ported upstream is `charmbracelet/bubbletea`, an
 * Elm-architecture TUI runtime with no text sanitizer — see `docs/MATCHUPS.md`):
 *   - `controlChars()` — extracted from sugar-bits' per-cell `sanitizeCell`
 *     helper (commit 114118393);
 *   - `cellValue()` — byte-identical to candy-query's `CellValue::sanitize()`
 *     (commit 8ac981ebf);
 *   - `untrusted()` — ported from sugar-dash (commit 8ac981ebf).
 * Consolidating them gives every component one audited injection boundary.
 * Provenance: `git log --follow -- candy-core/src/Util/Sanitize.php`.
 */
final class Sanitize
{
    /**
     * First codepoint of the Basic Multilingual Plane Private Use Area — the
     * block SugarCraft reserves for in-frame markup (zone sentinels, image
     * markers). Pinned against {@see \SugarCraft\Core\ImageOverlay}'s marker
     * window so the two allocations stay auditable from one place.
     */
    public const PUA_BMP_FIRST = 0xE000;

    /** Last codepoint of the reserved BMP Private Use Area (U+F8FF). */
    public const PUA_BMP_LAST = 0xF8FF;

    /** U+E000 — the zone-OPEN sentinel candy-mouse's Mark emits / Scan parses. */
    public const ZONE_SENTINEL_OPEN = "\xEE\x80\x80";

    /** U+E001 — the zone-CLOSE sentinel candy-mouse's Mark emits / Scan parses. */
    public const ZONE_SENTINEL_CLOSE = "\xEE\x80\x81";

    /**
     * UTF-8 spellings of the whole U+E000–U+F8FF block, matched byte-wise.
     *
     * No `/u` flag on purpose, for the same reason {@see untrusted()}'s C0 sweep
     * has none: a Unicode-mode match FAILS (returns null) on input carrying a
     * single malformed sequence, and any fallback then hands the hostile bytes
     * back. These three-byte shapes cannot occur inside another character, so
     * the byte scan is both exact for well-formed input and fail-closed for
     * broken input — it can only ever over-match a stray 0xEE/0xEF lead byte,
     * which is the safe direction for a strip.
     */
    private const PUA_BMP_PATTERN = '/\xEE[\x80-\xBF][\x80-\xBF]|\xEF[\x80-\xA3][\x80-\xBF]/';

    // Visible stand-in for a neutralized control byte: · (U+00B7 MIDDLE DOT).
    private const CELL_REPLACEMENT = "\xC2\xB7";

    // Visible stand-in for a collapsed newline: ↵ (U+21B5 DOWNWARDS ARROW
    // WITH CORNER LEFTWARDS). Reads as "line ended here" — chosen over → so
    // the glyph carries its return semantics.
    private const NEWLINE_GLYPH = "\xE2\x86\xB5";

    /**
     * Strip C0 control characters from caller-supplied text so they cannot
     * inject newlines or corrupt the TUI render. NOT an escape-sequence
     * stripper, and NOT SGR-preserving — read the contract below before
     * "fixing" the code to match a memory of these docs.
     *
     * Contract (pinned byte-for-byte by `SanitizeTest`):
     *   - `\n`, `\r`, `\t` each fold to exactly one space (0x20).
     *   - Every other C0 byte — `\x00`-`\x08`, `\x0b`, `\x0c`, `\x0e`-`\x1f` —
     *     is deleted. ESC (0x1b) falls inside that last range and is therefore
     *     deleted too.
     *   - DEL (0x7f) and the C1 range (0x80-0x9f, as raw bytes or as UTF-8
     *     code points) pass through untouched. That is a declared scope
     *     boundary, not an oversight — see the siblings below.
     *
     * Why ESC is dropped rather than preserved: no default policy over
     * arbitrary caller-supplied text may keep the escape introducer alive. A
     * surviving ESC lets that text open a CSI/OSC/DCS sequence of the
     * attacker's choosing, and desynchronises the frame-diff renderer's line
     * model — the exact injection this class exists to close. Preserving SGR
     * here would trade a proven defence for a cosmetic convenience. What
     * survives of `\x1b[31m` is the inert printable text `[31m`: ugly in a
     * label, but never re-interpreted by a terminal. Components that want
     * styled output sanitize the PLAIN text and apply their own SGR after
     * (see sugar-bits `Help`, `Tabs`, `Table`), never before.
     *
     * Need more than a C0 sweep? Pick the hardened sibling rather than
     * widening this method — the gap matters most for 8-bit input, where a raw
     * `\x9b` survives this method as a fully functional CSI introducer:
     *   - {@see untrusted()} — removes whole escape sequences and the lone C1
     *     controls inside {@see Ansi::strip()}, then the remaining C0 controls
     *     and DEL. It is NOT single-line: TAB, LF and CR pass through by
     *     design, so a caller that needs one line must fold newlines itself.
     *     The policy for terminal-bound text from a process, socket, or user.
     *   - {@see cellValue()} — replaces C0, DEL and C1 with a visible stand-in
     *     (· for controls, ↵ for collapsed newlines, U+FFFD for undecodable
     *     bytes) and repairs invalid UTF-8. The policy for cell grids.
     *
     * @param string $s Caller-supplied text.
     * @return string Single-line text: no C0 bytes, no ESC, unchanged payload
     *                bytes outside those ranges.
     */
    public static function controlChars(string $s): string
    {
        $s = str_replace(["\n", "\r", "\t"], ' ', $s);
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s) ?? $s;
    }

    /**
     * Turn an arbitrary already-stringified cell value into something safe
     * for a cell-grid renderer: repair invalid UTF-8, collapse (or preserve)
     * newlines, and replace every remaining control byte with a visible dot.
     *
     * Invalid UTF-8 bytes become U+FFFD rather than being silently dropped,
     * so binary/BLOB data keeps a 1:1 visible stand-in and downstream
     * width/truncation math stays sane. This is the canonical policy that
     * the sugar-table and candy-query grids share.
     *
     * @param string $value            Raw, already-stringified cell value.
     * @param bool   $preserveNewlines When true, keep line breaks as "\n" so
     *                                  multiline callers can explode() on them;
     *                                  when false, collapse every newline
     *                                  variant to the ↵ glyph (single line).
     * @return string Sanitized string safe for the terminal buffer.
     */
    public static function cellValue(string $value, bool $preserveNewlines = false): string
    {
        // 1. Repair invalid UTF-8 (binary data) so width/truncation stay sane.
        //    Malformed bytes are substituted with U+FFFD, not dropped, keeping
        //    a visible marker where corrupted bytes were.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD); // U+FFFD REPLACEMENT CHARACTER
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
        }

        // 2. Handle newlines FIRST — before the C0 sweep — because \n (0x0A)
        //    and \r (0x0D) live in the C0 block and would otherwise be caught
        //    below. The two branches also differ in which C0 sweep follows, so
        //    that \n survives only when the caller asked to preserve it.
        if ($preserveNewlines) {
            // Multiline: normalize CRLF/CR to LF for consistent explode("\n").
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            // C0 + DEL sweep that spares the LF we just normalized.
            $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', self::CELL_REPLACEMENT, $value) ?? $value;
        } else {
            // Single-line: collapse every newline variant to the ↵ glyph.
            $value = str_replace(["\r\n", "\r", "\n"], self::NEWLINE_GLYPH, $value);
            // C0 + DEL sweep over everything that remains (no newline survivors).
            $value = preg_replace('/[\x00-\x1F\x7F]/', self::CELL_REPLACEMENT, $value) ?? $value;
        }

        // 3. Neutralize the C1 control range (U+0080–U+009F), now valid UTF-8.
        //    The /u flag matches code points, not raw continuation bytes.
        $value = preg_replace('/[\x{0080}-\x{009F}]/u', self::CELL_REPLACEMENT, $value) ?? $value;

        return $value;
    }

    /**
     * Strip all C0 control bytes (\x00–\x1f) and C1 (\x80–\x9f)
     * except \t (\x09), \n (\x0a) and \r (\x0d), and remove every escape
     * sequence — CSI/OSC/SGR introduced by \x1b, the 8-bit C1 forms
     * (\x9b CSI, \x90 DCS, \x9d OSC, \x9f APC …), and string-sequence
     * payloads (DCS/SOS/PM/APC, sixel and Kitty included).
     *
     * Use this on any string that originates from an external process,
     * network response, or user-controlled source before writing to the
     * terminal. Versus {@see controlChars()}: on 7-bit input both guarantee no
     * ESC survives and the difference is residue — that method deletes the ESC
     * introducer (and an OSC's BEL terminator, being C0) but leaves the
     * sequence's printable body behind as inert text (`\x1b[31m` → `[31m`),
     * and it keeps DEL, which this one deletes. On 8-bit input the difference
     * is safety: a raw `\x9b` passes `controlChars()` untouched and still acts
     * as a live CSI introducer, so only here is plain text actually delivered.
     * Neither preserves ESC-introduced SGR, and plain-text sinks render no
     * colour either way, so the full strip costs nothing.
     *
     * Declared scope boundary: Private-Use codepoints are well-formed text, not
     * control bytes, so U+E000–U+F8FF survives this method. That is fine for a
     * plain-text sink and unsafe for a frame carrying candy-mouse zone markup —
     * for the latter use {@see untrustedForMarkedFrames()}, or add
     * {@see stripZoneSentinels()} to a path that must keep other PUA glyphs.
     *
     * @param string $s Untrusted input string
     * @return string Sanitized string safe for terminal output
     */
    public static function untrusted(string $s): string
    {
        // Step 1: remove every escape sequence in 7-bit and 8-bit form,
        // including DCS/APC/PM/SOS payloads and lone C1 controls. The
        // UTF-8-aware lone-C1 rule (a 0x80-0x9f byte counts as a control
        // only when it does not continue a lead byte 0xC0-0xF7) lives in
        // Ansi::strip() so the sanitizer and the width math cannot diverge.
        $stripped = Ansi::strip($s);

        // Step 2: strip the remaining C0 control bytes and DEL.
        // Preserved: TAB (0x09), LF (0x0a), CR (0x0d).
        // Dropped: NUL..BS (0x00-0x08), VT (0x0b), FF (0x0c),
        //          SO..US (0x0e-0x1f), DEL (0x7f).
        //
        // Byte-oriented on purpose (NO /u flag): the class matches only
        // ASCII bytes, which can never occur inside a well-formed UTF-8
        // multi-byte sequence, yet a /u pattern FAILS on invalid UTF-8 —
        // and the old `?? $stripped` fallback then let every C0 control
        // (BEL included) through. Fail-closed beats fallback-to-hostile.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $stripped) ?? '';
    }

    /**
     * Remove the zone sentinel pair (U+E000 / U+E001) from text, leaving every
     * other codepoint — including the rest of the Private Use Area — alone.
     *
     * This is the security half of "untrusted text may not speak the zone
     * language". Zone markup needs a sentinel at both ends, so with neither able
     * to survive this sweep, injected input can neither register an
     * attacker-chosen click target in the hit-test registry nor forge the
     * duplicate/unclosed ids that make the zone parse throw. What is left of a
     * forged `U+E000 id U+E001` triple is its inert id text.
     *
     * Deliberately surgical rather than block-wide: image marker cells
     * ({@see \SugarCraft\Core\ImageOverlay}, U+E000 + id) and Nerd Font glyphs
     * share the block and must keep flowing through a display path. Callers that
     * want a guaranteed PUA-free string use {@see stripPrivateUse()}.
     *
     * `str_replace` on the literal byte spellings, never a `/u` regex: the same
     * fail-closed law as {@see untrusted()} — malformed UTF-8 must not be able to
     * turn the strip into a no-op.
     *
     * @param string $s Text about to be painted into a zone-marked frame.
     * @return string The same text with every zone sentinel removed.
     */
    public static function stripZoneSentinels(string $s): string
    {
        return str_replace([self::ZONE_SENTINEL_OPEN, self::ZONE_SENTINEL_CLOSE], '', $s);
    }

    /**
     * Remove every Basic Multilingual Plane Private Use codepoint
     * (U+E000–U+F8FF) — the whole arena SugarCraft allocates markup from.
     *
     * The blunt instrument: for a surface that renders no images and no patched-
     * font glyphs, this is the strongest guarantee that no foreign markup reaches
     * a zone scanner. Softer callers that must keep U+E002-and-up image markers or
     * icon fonts alive use {@see stripZoneSentinels()}. Supplementary-plane PUA
     * (U+F0000…U+10FFFD, where Nerd Fonts put most of their glyphs) is NOT touched
     * — it cannot form zone markup, so stripping it would cost glyphs for nothing.
     *
     * @param string $s Text to render PUA-free.
     * @return string The same text with the BMP Private Use block removed.
     */
    public static function stripPrivateUse(string $s): string
    {
        return preg_replace(self::PUA_BMP_PATTERN, '', $s) ?? '';
    }

    /**
     * The policy for text that originated outside this process AND lands in a
     * frame carrying candy-mouse zone markup: {@see untrusted()} plus
     * {@see stripZoneSentinels()}.
     *
     * {@see untrusted()} alone is not enough there, because a Private-Use
     * sentinel is well-formed text with no control meaning to the ANSI sweep —
     * hostile model or tool output carrying it would reach the zone parser
     * verbatim. Composing the two here keeps the predicate in one audited place
     * instead of re-rolled per application.
     *
     * Order matters only for readability: the ANSI/C0 sweep cannot create
     * sentinels, and the sentinel sweep cannot create escapes.
     *
     * @param string $s Untrusted text destined for a zone-marked frame.
     * @return string Terminal-safe text with no zone sentinels left.
     */
    public static function untrustedForMarkedFrames(string $s): string
    {
        return self::stripZoneSentinels(self::untrusted($s));
    }
}
