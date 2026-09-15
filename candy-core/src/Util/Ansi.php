<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Lang;

/**
 * Low-level ANSI / CSI / OSC escape-sequence constants and helpers.
 *
 * Higher-level callers (Style, Renderer, Cursor) compose the strings
 * here to build their final output. Direct use is uncommon — prefer
 * the typed APIs in candy-sprinkles wherever possible.
 */
final class Ansi
{
    public const ESC = "\x1b";
    public const CSI = "\x1b[";
    public const OSC = "\x1b]";
    public const APC = "\x1b_";
    public const DCS = "\x1bP";
    public const PM  = "\x1b^";
    public const ST  = "\x1b\\";
    public const BEL = "\x07";
    // C0 locking shifts (ansicode.txt:133-134 — "SO Shift Out, switch to G1"
    // / "SI Shift In, switch to G0"): SO swaps G1 into GL, SI swaps G0 back.
    // Needed to *use* a G1 SCS designation — designating without
    // invoking renders nothing different.
    public const SO  = "\x0e";
    public const SI  = "\x0f";

    public const RESET     = 0;
    public const BOLD      = 1;
    public const FAINT     = 2;
    public const ITALIC    = 3;
    public const UNDERLINE = 4;
    public const BLINK       = 5;
    public const RAPID_BLINK = 6;
    public const REVERSE     = 7;
    public const CONCEAL   = 8;
    public const STRIKE    = 9;
    public const OVERLINE  = 53;

    // DEC private mode constants (CSI ? <n> h/l)
    public const DECCKM             = 1;  // Application cursor keys
    public const DECAWM             = 7;  // Auto-wrap mode
    public const MOUSE_NORMAL       = 1000; // Mouse tracking (button events)
    public const MOUSE_BUTTON       = 1002; // Button-event tracking
    public const MOUSE_ANY          = 1003; // All motion tracking
    public const MOUSE_SGR          = 1006; // SGR mouse coordinates
    public const ALT_SCREEN_BUFFER   = 1049; // Alternate screen buffer
    public const BRACKETED_PASTE    = 2004; // Bracketed paste mode
    public const SYNCHRONIZED_OUTPUT = 2026; // Synchronized output

    // SCS designator finals — the four sets candy-vt's `Charset\Charsets`
    // translates. Named here so an emitter call site can only spell a
    // designator the receiver actually models; candy-vcr/tests/
    // CoreEmitterRoundTripTest.php pins this roster against the emulator's own
    // public constants in both directions, so neither side can grow alone.
    public const CHARSET_ASCII        = 'B'; // US ASCII (the default)
    public const CHARSET_DEC_SPECIAL  = '0'; // DEC Special Graphics / line drawing
    public const CHARSET_UK           = 'A'; // UK Latin-1 (0x23 = £)
    public const CHARSET_NO_BREAK_SPACE = 'U'; // ISO Latin-1, 0xA0 renders as space

    public static function sgr(int ...$codes): string
    {
        if ($codes === []) {
            return self::CSI . 'm';
        }
        return self::CSI . implode(';', $codes) . 'm';
    }

    public static function reset(): string
    {
        return self::CSI . '0m';
    }

    public static function fg16(int $code): string
    {
        if ($code < 30 || ($code > 37 && $code < 90) || $code > 97) {
            throw new \InvalidArgumentException(Lang::t('ansi.invalid_fg_code', ['code' => $code]));
        }
        return self::CSI . $code . 'm';
    }

    public static function bg16(int $code): string
    {
        if ($code < 40 || ($code > 47 && $code < 100) || $code > 107) {
            throw new \InvalidArgumentException(Lang::t('ansi.invalid_bg_code', ['code' => $code]));
        }
        return self::CSI . $code . 'm';
    }

    public static function fg256(int $index): string
    {
        self::assertByte($index, '256-color index');
        return self::CSI . '38;5;' . $index . 'm';
    }

    public static function bg256(int $index): string
    {
        self::assertByte($index, '256-color index');
        return self::CSI . '48;5;' . $index . 'm';
    }

    public static function fgRgb(int $r, int $g, int $b): string
    {
        self::assertByte($r, 'red');
        self::assertByte($g, 'green');
        self::assertByte($b, 'blue');
        return self::CSI . "38;2;$r;$g;{$b}m";
    }

    public static function bgRgb(int $r, int $g, int $b): string
    {
        self::assertByte($r, 'red');
        self::assertByte($g, 'green');
        self::assertByte($b, 'blue');
        return self::CSI . "48;2;$r;$g;{$b}m";
    }

    public static function cursorUp(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'A';
    }
    public static function cursorDown(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'B';
    }
    public static function cursorRight(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'C';
    }
    public static function cursorLeft(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'D';
    }
    public static function cursorTo(int $row, int $col): string
    {
        return self::CSI . max(1, $row) . ';' . max(1, $col) . 'H';
    }
    public static function cursorHide(): string
    {
        return self::CSI . '?25l';
    }
    public static function cursorShow(): string
    {
        return self::CSI . '?25h';
    }

    /**
     * Emit DECSCUSR to set the cursor shape (block / underline /
     * bar). Each shape has a steady and blinking variant; pass
     * `$blink: true` for the blinking version. Pass shape `null`
     * (`CSI 0 q`) to restore the terminal default.
     */
    public static function cursorShape(?\SugarCraft\Core\CursorShape $shape, bool $blink = false): string
    {
        if ($shape === null) {
            return self::CSI . '0 q';
        }
        // The DECSCUSR codes are paired: even = steady, odd = blink.
        $code = $blink ? $shape->value - 1 : $shape->value;
        return self::CSI . $code . ' q';
    }
    public static function cursorSave(): string
    {
        return self::ESC . '7';
    }
    public static function cursorRestore(): string
    {
        return self::ESC . '8';
    }

    public static function eraseLine(): string
    {
        return self::CSI . '2K';
    }
    public static function eraseScreen(): string
    {
        return self::CSI . '2J';
    }
    public static function eraseToEnd(): string
    {
        return self::CSI . '0J';
    }

    /** Erase from the cursor to the start of the line (`CSI 1K`). */
    public static function eraseToLineStart(): string
    {
        return self::CSI . '1K';
    }

    /** Erase from the cursor to the end of the line (`CSI 0K`). */
    public static function eraseToLineEnd(): string
    {
        return self::CSI . '0K';
    }

    /** Erase from the start of the screen to the cursor (`CSI 1J`). */
    public static function eraseToScreenStart(): string
    {
        return self::CSI . '1J';
    }

    /**
     * Set the scrolling region (DECSTBM): rows below the top margin and
     * above the bottom margin scroll, everything outside is fixed.
     * Both arguments are 1-based row numbers. Pass `top=1, bottom=0`
     * to reset to the full screen.
     */
    public static function setScrollRegion(int $top, int $bottom): string
    {
        if ($top < 1) {
            $top = 1;
        }
        if ($bottom <= 0) {
            return self::CSI . 'r';
        }
        return self::CSI . $top . ';' . $bottom . 'r';
    }

    /** Reset the scroll region to cover the whole screen. */
    public static function resetScrollRegion(): string
    {
        return self::CSI . 'r';
    }

    /** Scroll the active region up by `$n` lines (`CSI <n> S`). */
    public static function scrollUp(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'S';
    }

    /** Scroll the active region down by `$n` lines (`CSI <n> T`). */
    public static function scrollDown(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'T';
    }

    /** Insert `$n` blank lines at the cursor (`CSI <n> L`). */
    public static function insertLine(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'L';
    }

    /** Delete `$n` lines starting at the cursor (`CSI <n> M`). */
    public static function deleteLine(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'M';
    }

    /** Insert `$n` blank cells at the cursor, shifting right (`CSI <n> @`). */
    public static function insertChar(int $n = 1): string
    {
        return self::CSI . max(1, $n) . '@';
    }

    /** Delete `$n` cells starting at the cursor, shifting left (`CSI <n> P`). */
    public static function deleteChar(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'P';
    }

    /** Repeat the previous character `$n` times (`CSI <n> b`). */
    public static function repeatChar(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'b';
    }

    /**
     * Emit the opening OSC 8 hyperlink sequence for `$url`. Use
     * {@see hyperlinkClose()} to close the link after the linked text.
     *
     * Encoding: `\x1b]8;<id>;<url>\x1b\\`. ST (the String Terminator)
     * is preferred over BEL because some terminals mis-render BEL
     * inside an OSC.
     *
     * Mirrors charmbracelet/x/ansi. LinkFormatter.
     *
     * @param string      $uri  Target URL
     * @param string|null $id   Optional link ID for shared destinations
     */
    public static function hyperlinkOpen(string $uri, ?string $id = null): string
    {
        $params = $id === null ? '' : 'id=' . self::stripOscControlBytes($id);
        return self::OSC . '8;' . $params . ';' . self::stripOscControlBytes($uri) . self::ST;
    }

    /**
     * Emit the OSC 8 hyperlink terminator (no-op close, used after
     * the linked text to restore normal underline state).
     *
     * Mirrors charmbracelet/x/ansi. LinkFormatter.
     */
    public static function hyperlinkClose(): string
    {
        return self::OSC . '8;;' . self::ST;
    }

    /**
     * Wrap `$text` in an OSC 8 hyperlink so terminals that support it
     * (iTerm2, WezTerm, Kitty, GNOME Terminal, Windows Terminal) render
     * `$text` as a clickable link to `$url`. `$id` lets multiple
     * hyperlinks share the same logical destination — pass an empty
     * string for one-shot links. The trailing `OSC 8 ; ; ST` closes
     * the link state so subsequent text isn't underlined.
     *
     * Encoding: `\x1b]8;<id>;<url>\x1b\\<text>\x1b]8;;\x1b\\`. ST (the
     * String Terminator) is preferred over BEL because some terminals
     * mis-render BEL inside an OSC.
     */
    public static function hyperlink(string $url, string $text, string $id = ''): string
    {
        return self::hyperlinkOpen($url, $id === '' ? null : $id)
             . $text
             . self::hyperlinkClose();
    }

    /**
     * Save the cursor position via DECSC (`ESC 7`). Same as
     * `cursorSave()` — kept as an alias for parity with terminal-control
     * documentation that uses the SCO/DECSC distinction.
     */
    public static function decsc(): string
    {
        return self::ESC . '7';
    }
    public static function decrc(): string
    {
        return self::ESC . '8';
    }

    /** Save the cursor position via SCO `CSI s`. */
    public static function scoSave(): string
    {
        return self::CSI . 's';
    }
    /** Restore the cursor position via SCO `CSI u`. */
    public static function scoRestore(): string
    {
        return self::CSI . 'u';
    }

    // RIS / DECALN / SCS emitters. These are standards sequences, so each docblock
    // cites ECMA-48, VT510 and ansicode.txt directly, and this file's
    // `Mirrors charmbracelet/x/ansi.*` convention is honoured only where this repo
    // already records an upstream symbol to mirror (see scs()) rather than guessed
    // at. candy-ansi holds the parse side of all three and no emitter of its own.

    /**
     * RIS — Reset to Initial State (`ESC c`).
     *
     * The full power-on reset. Distinct from {@see reset()}, which is only
     * `SGR 0` (the pen): RIS also restores modes, margins, tab stops, the SCS
     * designations and — on a physical terminal — the screen contents, which is
     * why it is the right teardown for a crashed or replayed session. candy-vt
     * models it as `ScreenHandler::hardReset()`, which like the hardware
     * PRESERVES scrollback, the window title and the OSC 4 palette — matching
     * charmbracelet/x/vt `Emulator.fullReset()`.
     *
     * ECMA-48 two-character escape: `ESC` + a lowercase final, which X3.64
     * Appendix E reserves for independent control functions (ansicode.txt:297-300;
     * `143 63 c * RIS` at :307); VT510 ch. 4; xterm ctlseqs "ESC c".
     */
    public static function ris(): string
    {
        return self::ESC . 'c';
    }

    /**
     * DECALN — screen alignment test pattern (`ESC # 8`), filling the screen
     * with 'E' so an operator can adjust focus/geometry.
     *
     * WIRE SPELLING MATTERS: VT100 and xterm define DECALN as an intermediate
     * escape — `ESC`, intermediate `#` (0x23), final `8` (ansicode.txt:217) —
     * which is what this emits. The `CSI # 8` spelling that circulates in some
     * notes is not a complete sequence: a CSI final byte must be 0x40-0x7E, so
     * on a standards-conformant receiver `ESC [ # 8` stays inside the CSI, where
     * it consumes whatever the caller prints next as the final — eating output
     * instead of testing alignment. In xterm the CSI-`#` substate is the
     * palette stack (`csi_hash_table[]`, the `CSI # P/Q/R/S` XT*COLORS family),
     * never DECALN; in candy-ansi's mirrored table the sequence drops in
     * CsiIntermediate without dispatching. The misquote stays deliberately inert.
     *
     * candy-vt executes this pattern from the wire: `ESC # 8` reaches
     * `ScreenHandler::escDispatch()` as (0x38, 0x23), whose `#`-family arm
     * routes it to `displayAlignmentTest()` — closing the emitter→emulator
     * round trip (the emulator used to swallow it as a failed charset
     * designation). The bytes are pinned by an exact-byte test here and, in
     * candy-vcr, by the dispatch the parser really reports.
     *
     * VT510 ch. 4 (DECALN); ansicode.txt:217 ("#8 * DECALN - Alignment
     * display, fill screen with \"E\" to adjust focus").
     * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECALN)
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DECALN)
     */
    public static function decaln(): string
    {
        return self::ESC . '#8';
    }

    /**
     * SCS slot index (0-3, i.e. G0-G3) to its intermediate byte — the four
     * `(`/`)`/`*`/`+` positions of ansicode.txt:222, 240, 242, 244. Indexed
     * access makes an out-of-range slot a plain null lookup rather than a
     * silent default.
     *
     * @var array<int, string>
     */
    private const SCS_SLOTS = [0 => '(', 1 => ')', 2 => '*', 3 => '+'];

    /**
     * SCS — select a character set into one of the G0-G3 slots.
     *
     * The designation itself is state, not output: it changes how *later*
     * graphics are translated. G0 is invoked by default; G1 needs
     * {@see shiftOut()}, G2/G3 need a single shift. Recognised designators
     * in candy-vt are `B` (US ASCII), `0` (DEC Special Graphics), `A` (UK),
     * `U` (ISO Latin-1 no-break space); see {@see decSpecialGraphics()}.
     *
     * Fails fast on an unknown slot or a designator outside the 0x30-0x7E
     * designation-final range (see {@see isScsDesignator()}): such a string
     * would desynchronise the receiver's parser, which is precisely the class
     * of bug this emitter exists to prevent.
     *
     * Mirrors charmbracelet/x/ansi. SelectCharacterSet — the same
     * `ESC <intermediate> <final>` helper that candy-vt's `Charsets` docblock
     * records as its own upstream source (same wire shape; this port keys the
     * designation on a 0-3 slot index).
     *
     * ECMA-48 §25 (character set designation); ansicode.txt:222-249
     * ("SCS - Select G0/G1/G2/G3 character set").
     *
     * @see https://vt100.net/docs/vt510-rm/chapter4.html (SCS)
     */
    public static function scs(int $slot, string $designator): string
    {
        $intermediate = self::SCS_SLOTS[$slot] ?? null;
        if ($intermediate === null) {
            throw new \InvalidArgumentException(Lang::t('ansi.invalid_scs_slot', ['slot' => $slot]));
        }
        if (strlen($designator) !== 1 || !self::isScsDesignator(\ord($designator))) {
            throw new \InvalidArgumentException(Lang::t('ansi.invalid_scs_designator', [
                'designator' => $designator === '' ? '<empty>' : '<' . bin2hex($designator) . '>',
            ]));
        }

        return self::ESC . $intermediate . $designator;
    }

    /** Designate into G0 — `ESC ( F`, the slot GL reads from by default. */
    public static function scsG0(string $designator): string
    {
        return self::scs(0, $designator);
    }

    /** Designate into G1 — `ESC ) F`; invoke with {@see shiftOut()}. */
    public static function scsG1(string $designator): string
    {
        return self::scs(1, $designator);
    }

    /** Designate into G2 — `ESC * F`; a VT220+ slot, invoked by SS2/LS2. */
    public static function scsG2(string $designator): string
    {
        return self::scs(2, $designator);
    }

    /** Designate into G3 — `ESC + F`; a VT220+ slot, invoked by SS3/LS3. */
    public static function scsG3(string $designator): string
    {
        return self::scs(3, $designator);
    }

    /**
     * The classic VT100 line-drawing idiom: DEC Special Graphics into G0
     * (`ESC ( 0`), after which `lqqqqk` paints `┌────┐` (ansicode.txt:223).
     */
    public static function decSpecialGraphics(): string
    {
        return self::scs(0, self::CHARSET_DEC_SPECIAL);
    }

    /** US ASCII into G0 (`ESC ( B`) — restores plain text after line drawing. */
    public static function asciiCharset(): string
    {
        return self::scs(0, self::CHARSET_ASCII);
    }

    /** LS1 — Shift Out (`SO`, 0x0E): swap the G1 designation into GL. */
    public static function shiftOut(): string
    {
        return self::SO;
    }

    /** LS0 — Shift In (`SI`, 0x0F): swap the G0 designation back into GL. */
    public static function shiftIn(): string
    {
        return self::SI;
    }

    /** Set a horizontal tab stop at the current column (HTS). */
    public static function setTabStop(): string
    {
        return self::ESC . 'H';
    }
    /** Clear the tab stop at the current column (`CSI 0g`). */
    public static function clearTabStop(): string
    {
        return self::CSI . '0g';
    }
    /** Clear all tab stops (`CSI 3g`). */
    public static function clearAllTabStops(): string
    {
        return self::CSI . '3g';
    }
    /** Move forward to the next tab stop, `$n` times (`CSI <n> I`). */
    public static function tabForward(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'I';
    }
    /** Move backward to the previous tab stop, `$n` times (`CSI <n> Z`). */
    public static function tabBackward(int $n = 1): string
    {
        return self::CSI . max(1, $n) . 'Z';
    }

    public static function altScreenEnter(): string
    {
        return self::CSI . '?1049h';
    }
    public static function altScreenLeave(): string
    {
        return self::CSI . '?1049l';
    }

    /**
     * Synchronized output (DEC mode 2026). Wrap each rendered frame in
     * `syncBegin` / `syncEnd` so the terminal buffers the whole frame
     * before painting, eliminating tearing and partial-frame flashes
     * on slow terminals. Bubble Tea v2 enables this by default; we
     * follow.
     */
    public static function syncBegin(): string
    {
        return self::CSI . '?2026h';
    }
    public static function syncEnd(): string
    {
        return self::CSI . '?2026l';
    }

    /**
     * Grapheme cluster mode (DEC mode 2027). Tells the terminal to
     * report widths / cursor advances per grapheme cluster instead of
     * per code point — fixes the long-standing emoji-width drift.
     * Toggle once at program startup; restore on teardown.
     */
    public static function unicodeOn(): string
    {
        return self::CSI . '?2027h';
    }
    public static function unicodeOff(): string
    {
        return self::CSI . '?2027l';
    }

    public static function bracketedPasteOn(): string
    {
        return self::CSI . '?2004h';
    }
    public static function bracketedPasteOff(): string
    {
        return self::CSI . '?2004l';
    }

    public static function mouseAllOn(): string
    {
        return self::CSI . '?1000h' . self::CSI . '?1006h';
    }
    public static function mouseAllOff(): string
    {
        return self::CSI . '?1006l' . self::CSI . '?1000l';
    }

    /** Cell-motion tracking: report when a button is held and the mouse moves. */
    public static function mouseCellMotionOn(): string
    {
        return self::CSI . '?1002h' . self::CSI . '?1006h';
    }
    public static function mouseCellMotionOff(): string
    {
        return self::CSI . '?1006l' . self::CSI . '?1002l';
    }

    /** All-motion tracking: report every move regardless of button state. */
    public static function mouseAllMotionOn(): string
    {
        return self::CSI . '?1003h' . self::CSI . '?1006h';
    }
    public static function mouseAllMotionOff(): string
    {
        return self::CSI . '?1006l' . self::CSI . '?1003l';
    }

    public static function focusReportingOn(): string
    {
        return self::CSI . '?1004h';
    }
    public static function focusReportingOff(): string
    {
        return self::CSI . '?1004l';
    }

    /**
     * Ask the terminal where the cursor is. The reply comes back as a
     * CSI sequence: `ESC [ <row> ; <col> R` (DSR-CPR), parsed into a
     * {@see \SugarCraft\Core\Msg\CursorPositionMsg}.
     */
    public static function requestCursorPosition(): string
    {
        return self::CSI . '6n';
    }

    /**
     * Ask the terminal for its current default foreground colour. Reply
     * arrives as `OSC 10 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \SugarCraft\Core\Msg\ForegroundColorMsg}.
     */
    public static function requestForegroundColor(): string
    {
        return self::OSC . '10;?' . self::BEL;
    }

    /**
     * Ask the terminal for its current default background colour. Reply
     * arrives as `OSC 11 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \SugarCraft\Core\Msg\BackgroundColorMsg}. Useful for picking
     * a theme that contrasts the user's background.
     */
    public static function requestBackgroundColor(): string
    {
        return self::OSC . '11;?' . self::BEL;
    }

    /**
     * Set the terminal's default foreground colour (OSC 10) using
     * the standard `rgb:RR/GG/BB` form. Persists across the program
     * — terminals don't auto-restore on exit, so callers should
     * either capture the original via `Cmd::requestForegroundColor()`
     * and reset it on teardown, or accept the persistence.
     */
    public static function setForegroundColor(int $r, int $g, int $b): string
    {
        return self::OSC . sprintf('10;rgb:%02x/%02x/%02x', $r, $g, $b) . self::BEL;
    }

    public static function setBackgroundColor(int $r, int $g, int $b): string
    {
        return self::OSC . sprintf('11;rgb:%02x/%02x/%02x', $r, $g, $b) . self::BEL;
    }

    /**
     * Ask the terminal for its current cursor colour. Reply arrives as
     * `OSC 12 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \SugarCraft\Core\Msg\CursorColorMsg}.
     */
    public static function requestCursorColor(): string
    {
        return self::OSC . '12;?' . self::BEL;
    }

    /**
     * Ask the terminal to identify itself (XTVERSION). Reply arrives
     * as a DCS sequence: `ESC P > | <terminal name and version> ESC \`
     * (e.g. `xterm(367)` or `iTerm2 3.4.16`) and is parsed into
     * {@see \SugarCraft\Core\Msg\TerminalVersionMsg}. Useful for
     * gating capabilities (sixel, kitty keyboard, etc.) on the
     * specific terminal.
     */
    public static function requestTerminalVersion(): string
    {
        return self::CSI . '>0q';
    }

    /**
     * Ask the terminal whether a given mode is set (DECRQM). Reply
     * arrives as `CSI [?] <mode> ; <state> $ y` (DECRPM) and is
     * parsed into {@see \SugarCraft\Core\Msg\ModeReportMsg}.
     *
     * @param bool $private true for DEC private modes (mouse 1006,
     *                      sync 2026, unicode 2027, etc.); false for
     *                      ANSI modes.
     */
    public static function requestMode(int $mode, bool $private = true): string
    {
        return self::CSI . ($private ? '?' : '') . $mode . '$p';
    }

    /**
     * Set the system clipboard via OSC 52. The `$selection` byte
     * picks the destination — `c` (clipboard, default), `p` (X11
     * primary), `s` (secondary), `0`-`7` (cut buffers).
     */
    public static function setClipboard(string $text, string $selection = 'c'): string
    {
        return self::OSC . '52;' . $selection . ';' . base64_encode($text) . self::BEL;
    }

    /**
     * Ask the terminal to send back the contents of the named
     * selection. Reply arrives as `OSC 52 ; <selection> ; <base64>
     * BEL|ST` and is parsed into {@see \SugarCraft\Core\Msg\ClipboardMsg}.
     */
    public static function readClipboard(string $selection = 'c'): string
    {
        return self::OSC . '52;' . $selection . ';?' . self::BEL;
    }

    /**
     * Strip C0 control bytes from a string destined for an OSC body.
     * These bytes can terminate or escape the OSC sequence prematurely,
     * enabling terminal-escape injection attacks.
     *
     * @param string $s Raw string
     * @return string String with C0 controls and \x9c removed
     */
    private static function stripOscControlBytes(string $s): string
    {
        // \x00-\x1f (C0 controls) plus \x9c (8-bit ST terminator)
        return preg_replace('/[\x00-\x1f\x9c]/', '', $s) ?? '';
    }

    /**
     * Set the terminal window title (and icon name when `$icon` is
     * true). Uses OSC 2 by default; pass `$icon: true` to additionally
     * emit OSC 1 / OSC 0 for terminals that distinguish icon vs title.
     *
     * Control bytes in `$title` are stripped before emission to prevent
     * terminal-escape injection in the OSC body.
     */
    public static function setWindowTitle(string $title, bool $icon = false): string
    {
        $out = self::OSC . ($icon ? '0' : '2') . ';' . self::stripOscControlBytes($title) . self::BEL;
        return $out;
    }

    /**
     * Tell the terminal what the shell's current working directory is
     * via OSC 7. Used by iTerm2 / Terminal.app / WezTerm for "new tab
     * with same cwd" and split-pane clone semantics. `$host` is
     * optional and defaults to the local hostname (or empty if it
     * can't be determined).
     *
     * Control bytes in `$host` are stripped before emission; `$path`
     * is already safe via rawurlencode().
     */
    public static function setWorkingDirectory(string $path, string $host = ''): string
    {
        if ($host === '') {
            $host = gethostname() ?: '';
        }
        $encoded = str_replace('%2F', '/', rawurlencode($path));
        return self::OSC . '7;file://' . self::stripOscControlBytes($host) . $encoded . self::BEL;
    }

    /**
     * Set the terminal's taskbar-progress indicator (OSC 9;4 — the
     * ConEmu / WezTerm / Windows-Terminal protocol). `$percent` is
     * only meaningful for the Normal / Error / Warning states.
     */
    public static function setProgressBar(\SugarCraft\Core\ProgressBarState $state, int $percent = 0): string
    {
        $percent = max(0, min(100, $percent));
        return self::OSC . '9;4;' . $state->value . ';' . $percent . self::BEL;
    }

    /**
     * Push a new layer onto the Kitty progressive-keyboard flag stack
     * (`CSI > <flags> u`). Use the bit constants on
     * {@see \SugarCraft\Core\Msg\KeyboardEnhancementsMsg}.
     */
    public static function pushKittyKeyboard(int $flags): string
    {
        return self::CSI . '>' . $flags . 'u';
    }

    /**
     * Pop `$n` layers off the Kitty keyboard flag stack
     * (`CSI < <n> u`). Mirrors the entry from `pushKittyKeyboard()`
     * one-for-one — typically called from teardown.
     */
    public static function popKittyKeyboard(int $n = 1): string
    {
        return self::CSI . '<' . max(1, $n) . 'u';
    }

    /**
     * Ask the terminal which Kitty keyboard flags are currently
     * active (`CSI ? u`). Reply arrives as `CSI ? <flags> u` and is
     * parsed into {@see \SugarCraft\Core\Msg\KeyboardEnhancementsMsg}.
     */
    public static function requestKittyKeyboard(): string
    {
        return self::CSI . '?u';
    }

    /**
     * Strip every ANSI escape sequence from the input.
     *
     * Covers the full ECMA-48 escape taxonomy, in both 7-bit and 8-bit form:
     *
     *  - CSI  — `ESC [` / `0x9B` (ECMA-48 §15.9); consumed through the final
     *    byte (0x40–0x7E); every byte before the final is consumed
     *    unconditionally — including stray C0/DEL/C1 (the CSI grammar
     *    allows only 0x20–0x3F parameters there, so over-consuming is the
     *    fail-closed direction).
     *  - OSC  — `ESC ]` / `0x9D` (ECMA-48 §8.3.25, xterm "OSC"); terminated
     *    by ST (`ESC \` or `0x9C`) or — xterm's widely used extension — BEL.
     *  - String sequences DCS / SOS / PM / APC — `ESC P|X|^|_` and the 8-bit
     *    `0x90|0x98|0x9E|0x9F` (ECMA-48 §15.10–15.12, §8.3.9/8.3.13); the
     *    whole payload runs to ST. This is what stops sixel (`DCS … q … ST`)
     *    and Kitty graphics (`APC G … ST`) payloads from smuggling control
     *    text through a sanitizer.
     *  - Two-byte Fe escapes — `ESC` followed by 0x40–0x5F (e.g. `ESC \` ST,
     *    `ESC D` index), consumed as a pair.
     *  - Lone 8-bit C1 controls (0x80–0x9F outside a UTF-8 continuation
     *    chain, including a stray `0x9C` ST) — removed as single bytes.
     *
     * A sequence that hits end-of-input unterminated is discarded entirely
     * (fail-closed): a partial sequence is never released as a false "safe"
     * remainder that a re-synchronising terminal could execute. An `ESC`
     * inside a CSI or string sequence cancels it and the `ESC` is re-scanned
     * as a fresh introducer; `CAN`/`SUB` cancel a CSI outright (Williams VT
     * parser, ECMA-48 §5.4).
     *
     * Valid UTF-8 survives untouched: a 0x80–0x9F byte is treated as a C1
     * control unless it is part of a FULL well-formed UTF-8 sequence
     * (lead C2–F4 with §3.9-conformant continuations). Ill-formed or
     * truncated sequences claim nothing — matching Unicode's rule that the
     * byte after an ill-formed subpart is reprocessed, which is exactly how
     * `F0|C1|F5` + `\x9b` would otherwise smuggle an 8-bit CSI past the
     * sanitizer. A stray `ESC` consumes only itself so following text and
     * multi-byte characters survive; `ESC`-led charset designators
     * (`ESC ( B`) and ESC-prefixed charset invocations (`ESC 7`) leave
     * their tail bytes as inert visible text — no introducer survives, so
     * they cannot re-arm a sequence.
     *
     * The scan is a single O(n) byte pass with chunked copies — no regex,
     * no backtracking — so it stays safe on untrusted input of any size,
     * and is idempotent: `strip(strip($s)) === strip($s)`.
     *
     * @param string $s Potentially hostile input
     * @return string Text with every escape sequence removed
     */
    public static function strip(string $s): string
    {
        $out = '';
        $len = strlen($s);
        $i = 0;
        $seg = 0; // Start of the current passthrough run.
        while ($i < $len) {
            $b = \ord($s[$i]);
            if ($b === 0x1b) {
                $out .= substr($s, $seg, $i - $seg);
                $i = self::stripEscape($s, $i, $len);
                $seg = $i;
                continue;
            }
            if ($b >= 0xc2 && $b <= 0xf4) {
                // UTF-8: claim the run only when it is a FULL well-formed
                // sequence — valid lead, first continuation byte inside the
                // lead's legal range (Unicode Conformance §3.9), and all
                // remaining continuations present. Anything shorter or
                // out-of-range claims nothing: per §3.9 the C1-range byte
                // that follows an ill-formed lead is REPROCESSED (and a
                // conforming 8-bit decoder runs it as a control), so it must
                // fall through to the lone-C1 branch. This is also what
                // keeps the scan idempotent: claimed runs are self-contained
                // and never re-classified by a later pass.
                $shape = self::utf8Shape($b);
                $need = $shape[0];
                $j = $i + 1;
                $claimed = false;
                if ($j < $len && $shape[1] <= \ord($s[$j]) && \ord($s[$j]) <= $shape[2]) {
                    $claimed = true;
                    for (++$j; --$need > 0; $j++) {
                        if ($j >= $len || \ord($s[$j]) < 0x80 || \ord($s[$j]) > 0xbf) {
                            $claimed = false;
                            break;
                        }
                    }
                }
                if ($claimed) {
                    $i = $j;
                    continue;
                }
                $i++; // Ill-formed lead: inert text; next byte judged fresh.
                continue;
            }
            if ($b >= 0x80 && $b <= 0x9f) {
                $out .= substr($s, $seg, $i - $seg);
                $i = self::stripC1($s, $i, $len);
                $seg = $i;
                continue;
            }
            $i++;
        }
        return $out . substr($s, $seg);
    }

    /**
     * Well-formed UTF-8 shape of a lead byte (Unicode Conformance §3.9):
     * `[total continuation bytes required, low, high]` for the FIRST
     * continuation byte — E0 excludes overlongs (A0–BF), ED excludes
     * surrogates (80–9F), F0/F4 clamp the codepoint range (90–BF / 80–8F).
     * Bytes outside C2–F4 are not leads (C0/C1 overlong, F5–FF out of range)
     * and never claimed.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function utf8Shape(int $lead): array
    {
        return match (true) {
            $lead >= 0xf0 => $lead === 0xf0
                ? [3, 0x90, 0xbf]
                : ($lead === 0xf4 ? [3, 0x80, 0x8f] : [3, 0x80, 0xbf]),
            $lead >= 0xe0 => $lead === 0xe0
                ? [2, 0xa0, 0xbf]
                : ($lead === 0xed ? [2, 0x80, 0x9f] : [2, 0x80, 0xbf]),
            default => [1, 0x80, 0xbf],
        };
    }

    /**
     * Consume one 7-bit `ESC`-introduced sequence; returns the index just
     * past it (an unterminated sequence runs to end of input and is dropped).
     */
    private static function stripEscape(string $s, int $i, int $len): int
    {
        $next = $i + 1 < $len ? ord($s[$i + 1]) : -1;
        if ($next === 0x5b) { // ESC [ — CSI
            return self::stripCsi($s, $i + 2, $len);
        }
        if ($next === 0x5d) { // ESC ] — OSC (BEL also terminates, xterm)
            return self::stripString($s, $i + 2, $len, true);
        }
        // ESC P (DCS), ESC X (SOS), ESC ^ (PM), ESC _ (APC): string
        // sequences terminated only by ST — never by BEL.
        if ($next === 0x50 || $next === 0x58 || $next === 0x5e || $next === 0x5f) {
            return self::stripString($s, $i + 2, $len, false);
        }
        // Any other ECMA-48 Fe final (0x40–0x5f: ESC M, ESC D, ESC \ …) is a
        // two-byte escape. Anything else (lowercase text after a stray ESC,
        // a control byte, or a UTF-8 lead/continuation byte 0x80–0xff) is a
        // LONE ESC — skip only the ESC; the main scan then reads the bytes
        // after it on their own merits, so ordinary text and whole multi-byte
        // characters survive.
        return ($next >= 0x40 && $next <= 0x5f) ? $i + 2 : $i + 1;
    }

    /**
     * Consume a CSI body after its introducer; $i is the first body byte.
     */
    private static function stripCsi(string $s, int $i, int $len): int
    {
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b >= 0x40 && $b <= 0x7e) {
                return $i + 1; // Final byte — the CSI is complete.
            }
            if ($b === 0x1b) {
                return $i; // ESC cancels; the outer scan re-reads it fresh.
            }
            if ($b === 0x18 || $b === 0x1a) {
                return $i + 1; // CAN/SUB cancel the sequence (ECMA-48 §5.4).
            }
            $i++;
        }
        return $i; // Truncated CSI — discard the remainder.
    }

    /**
     * Consume a string-sequence body (OSC/DCS/SOS/PM/APC) after its
     * introducer, up to ST (`ESC \` or `0x9C`) or, when `$belTerminates`
     * (OSC only), BEL. A stray `ESC` not starting an ST cancels the string
     * and is re-scanned as a new introducer (Williams VT parser); a sequence
     * that hits end of input discards its payload entirely.
     */
    private static function stripString(string $s, int $i, int $len, bool $belTerminates): int
    {
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b === 0x1b) {
                if ($i + 1 < $len && $s[$i + 1] === '\\') {
                    return $i + 2; // ST — sequence complete.
                }
                return $i; // Cancel; re-scan this ESC as a fresh introducer.
            }
            if ($b === 0x07 && $belTerminates) {
                return $i + 1;
            }
            if ($b === 0x9c) {
                return $i + 1; // 8-bit ST.
            }
            $i++;
        }
        return $i; // Truncated string sequence — payload never resurfaces.
    }

    /**
     * Consume a lone 8-bit C1 byte: the introducer forms dispatch into the
     * same CSI/string consumers as their 7-bit equivalents; every other C1
     * (and a stray 0x9C ST) is a single-byte control.
     */
    private static function stripC1(string $s, int $i, int $len): int
    {
        return match ($s[$i]) {
            "\x9b" => self::stripCsi($s, $i + 1, $len),
            "\x9d" => self::stripString($s, $i + 1, $len, true),
            "\x90", "\x98", "\x9e", "\x9f" => self::stripString($s, $i + 1, $len, false),
            default => $i + 1,
        };
    }



    private static function assertByte(int $v, string $label): void
    {
        if ($v < 0 || $v > 255) {
            throw new \InvalidArgumentException(Lang::t('ansi.component_out_of_range', ['label' => $label, 'value' => $v]));
        }
    }

    /**
     * Is `$byte` a legal SCS designation final? The accepted band is 0x30-0x7E —
     * ECMA-48's Fp/Fe/Fs final bands taken together (the names are ECMA-48's;
     * ansicode.txt:222-249 is the designator inventory). That is wider than the
     * 0x40-0x7E range of ordinary escape finals because DEC's own sets are
     * digits: `(0` line drawing, `(<` supplemental graphics (ansicode.txt:223-230).
     *
     * Below 0x30 nothing designates, so no charset can land: 0x20-0x2F collects as
     * an *additional* intermediate at this position (the same collect rule is what
     * lets ansicode.txt:246-249 spell the `ESC , - . /` sets, there as *leading*
     * intermediates), a C0 byte is executed with the escape left open — CAN/SUB
     * likewise execute but then abandon the sequence to Ground, and ESC discards it
     * by starting a new escape — while DEL is ignored in place.
     */
    private static function isScsDesignator(int $byte): bool
    {
        return $byte >= 0x30 && $byte <= 0x7e;
    }

    /**
     * Emit an iTerm2 / WezTerm inline image via OSC 1337.
     *
     * Mirrors charmbracelet/x/ansi. Iterm2Renderer.
     *
     * @param string $base64Png  Base64-encoded PNG bytes
     * @param array  $opts       Optional keys: width, height (cell count or
     *                           Npx/Npt), preserveAspectRatio (bool),
     *                           inline (bool, default true)
     */
    public static function iterm2InlineImage(string $base64Png, array $opts = []): string
    {
        // iTerm2 inline-image protocol:
        //   OSC 1337 ; File = <key=value;key=value;…> : <base64 data> BEL
        // The arguments go in `File=…`, then a COLON, then the base64 payload.
        // (The previous form put the base64 straight after `File=` and the
        // arguments after it — not valid, so terminals printed the payload.)
        $args = [];
        if (isset($opts['width'])) {
            $args[] = 'width=' . $opts['width'];
        }
        if (isset($opts['height'])) {
            $args[] = 'height=' . $opts['height'];
        }
        if (isset($opts['preserveAspectRatio'])) {
            $args[] = 'preserveAspectRatio=' . ($opts['preserveAspectRatio'] ? '1' : '0');
        }
        $args[] = 'inline=1';

        return self::OSC . '1337;File=' . implode(';', $args) . ':' . $base64Png . self::BEL;
    }

    /**
     * Remove the most recently displayed iTerm2 / WezTerm inline image
     * via the "Pop" action (OSC 1337).
     *
     * Mirrors charmbracelet/x/ansi. Iterm2Renderer.
     */
    public static function iterm2Delete(): string
    {
        return self::OSC . '1337;Pop' . self::BEL;
    }

    /**
     * Emit one self-contained Kitty graphics data chunk.
     *
     * Format: APC `ESC _ G m=<0|1>;<base64> ST` — the Kitty graphics
     * protocol is APC-based (xterm ctlseqs `ESC _`, ECMA-48 §8.3.1 APC),
     * and the `m` flag carries the more-chunks semantics: `m=1` keeps the
     * transaction open, `m=0` transmits the final chunk and closes it.
     *
     * Mirrors charmbracelet/x/ansi. GraphicsData.
     *
     * @param string $base64  Base64-encoded data chunk
     * @param bool   $more    True if more chunks follow (sets m=1)
     */
    public static function kittyGraphicsChunk(string $base64, bool $more): string
    {
        return self::APC . 'G' . 'm=' . ($more ? '1' : '0') . ';' . $base64 . self::ST;
    }

    /**
     * Emit the Kitty graphics protocol begin sequence — the first frame of
     * a chunked transmission.
     *
     * Format: APC `ESC _ G <key>=<value>,…[,m=1]; ST` (empty first data
     * chunk). The frame opens the transaction so every following
     * {@see kittyGraphicsChunk()} inherits these attributes until an
     * `m=0` chunk or {@see kittyGraphicsEnd()} closes it. `m=1` is appended
     * unless `$opts` already sets `m` explicitly.
     *
     * NOT DCS `ESC P q` — that introducer is DECSIXEL (vt3xx sixel graphics,
     * ECMA-48 §15.10 DCS), byte-identical to a sixel start and guaranteed
     * garbage on a sixel-capable terminal (ANSI audit defect, ansicode:277).
     *
     * Common keys:
     *   - a: action (T=inline transmit, p=place, d=delete)
     *   - i: image id
     *   - f: compression (100=none, 1=gzip)
     *   - c: width in terminal cells
     *   - r: height in terminal cells
     *   - x: x offset (cells)
     *   - y: y offset (cells)
     *   - z: z-index (layer)
     *   - s: source width (pixels)
     *   - v: source height (pixels)
     *   - q: quantization (0-100, 2 is default)
     *
     * For a one-shot control frame that must NOT open a chunked
     * transaction (place/delete by id), use {@see kittyGraphicsControl()}.
     *
     * Mirrors charmbracelet/x/ansi. GraphicsBegin.
     *
     * @param array<string, mixed> $opts  Key-value pairs for the begin sequence
     */
    public static function kittyGraphicsBegin(array $opts): string
    {
        $pairs = self::kittyFormatPairs($opts);
        // A null `m` means "unset" (kittyFormatPairs drops it) — the frame
        // still has to open the transaction, or later chunks are orphaned.
        if (($opts['m'] ?? null) === null) {
            $pairs = $pairs === '' ? 'm=1' : $pairs . ',m=1';
        }
        return self::APC . 'G' . $pairs . ';' . self::ST;
    }

    /**
     * Emit a complete single-frame Kitty graphics control sequence
     * (`a=p` place, `a=d` delete, transformation-only ops) — attributes
     * only, no data, no open transaction.
     *
     * Mirrors charmbracelet/x/ansi. GraphicsEnd-without-chunks.
     *
     * @param array<string, mixed> $opts  Key-value control pairs
     */
    public static function kittyGraphicsControl(array $opts): string
    {
        return self::APC . 'G' . self::kittyFormatPairs($opts) . self::ST;
    }

    /**
     * Emit the final end-of-transmission frame for Kitty graphics: an
     * empty `m=0` chunk that closes a transaction opened by
     * {@see kittyGraphicsBegin()} (redundant but harmless after a final
     * chunk already sent `m=0`).
     *
     * Mirrors charmbracelet/x/ansi. GraphicsEnd.
     */
    public static function kittyGraphicsEnd(): string
    {
        return self::APC . 'G' . 'm=0;' . self::ST;
    }

    /**
     * Clear a Kitty graphics protocol image by id (or all if id=0).
     *
     * Mirrors charmbracelet/x/ansi. KittyRenderer.
     */
    public static function kittyGraphicsClear(int $imageId = 0): string
    {
        return self::APC . 'G' . "a=d,i=$imageId" . self::ST;
    }

    /**
     * Render Kitty graphics options as comma-joined `key=value` pairs,
     * skipping nulls (absent attribute = terminal default).
     *
     * @param array<string, mixed> $opts
     */
    private static function kittyFormatPairs(array $opts): string
    {
        $pairs = [];
        foreach ($opts as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }
        return implode(',', $pairs);
    }

    /**
     * Emit the Sixel DCS header: DECSIXEL sixel-graphics mode.
     *
     * Full form: DCS P1 ; P2 ; ... q ST
     * P1=1 (graphics, not rulers), followed by zero params (uses
     * Sixel Defaults are fine: aspect ratio 2:1, no background extended).
     *
     * Mirrors charmbracelet/x/ansi. SixelRenderer.
     *
     * @param int $width  Pixel width of the full image
     * @param int $height Pixel height of the full image
     */
    public static function sixelDcsHeader(int $width, int $height): string
    {
        // DECSIXEL: `ESC P P1;P2;P3 q` then raster attributes
        // `" Pan;Pad;Ph;Pv`. P1=0 (1:1 aspect, set precisely by the raster),
        // P2=1 (pixels left at 0 stay transparent), P3=0. The raster's
        // `"1;1;W;H` declares the pixel aspect (1:1) and the image's pixel
        // width/height so the terminal reserves the right area. The `"` prefix
        // is REQUIRED — emitting bare `W;H` (as before) is not valid sixel and
        // terminals print the payload as text.
        return self::DCS . '0;1;0q"1;1;' . $width . ';' . $height;
    }

    /**
     * Emit a Sixel color introducer to DECLARE a palette entry (DECGCI).
     *
     * Format: DCS Pn; Pr; Pg; Pb $ ST
     * Each component is 0-100 (percentage of 0-255 range).
     *
     * Mirrors charmbracelet/x/ansi. SixelRenderer.
     *
     * @param int $index  Palette index 0-255
     * @param int $r      Red   0-255
     * @param int $g      Green 0-255
     * @param int $b      Blue  0-255
     */
    public static function sixelColorIntroducer(int $index, int $r, int $g, int $b): string
    {
        // DECGCI: `# Pc ; Pu ; Px ; Py ; Pz` with Pu=2 (RGB) and Px/Py/Pz as
        // 0-100 percentages. It is part of the ENCLOSING sixel DCS — NOT its own
        // device-control string. (The previous code wrapped each colour in its
        // own `DCS … ST`, which is invalid and breaks the whole image.)
        return '#'
            . $index
            . ';2;' . self::toSixelColor($r)
            . ';' . self::toSixelColor($g)
            . ';' . self::toSixelColor($b);
    }

    /**
     * Emit a Sixel color select sequence (DECGCR, no RGB) to activate
     * a previously declared palette entry for subsequent sixel data.
     *
     * Format: DCS Pn $ ST
     *
     * Mirrors charmbracelet/x/ansi. SixelRenderer.
     *
     * @param int $index  Palette index 0-255 to select
     */
    public static function sixelColorSelect(int $index): string
    {
        // DECGCR: select a previously declared colour with `# Pc` — again part
        // of the enclosing sixel DCS, not its own control string.
        return '#' . $index;
    }

    /**
     * Emit a Sixel pixel-data string for one band (6 rows at a time).
     *
     * Each 6-row band is encoded left-to-right, top-to-bottom within
     * the band. For each pixel column a byte is emitted — bits 0-5
     * represent the six rows: bit 0 = top row of the band, bit 5 = bottom.
     * The byte value is the palette index ORed with 0x3F (63).
     *
     * A repeat count prefix "pn" (where n>1) precedes runs of the same
     * palette index for column efficiency, encoded as (count + 63) using
     * printable ASCII range 63-126.
     *
     * Mirrors charmbracelet/x/ansi. SixelRenderer.
     *
     * @param list<int> $column  Palette indices for each row in this band column
     * @param int|null $repeat   Run-length repeat count (null = single pixel)
     *
     * @return non-empty-string
     */
    public static function sixelPixelData(array $column, ?int $repeat = null): string
    {
        $pal = $column[0] ?? 0;
        if ($repeat !== null && $repeat > 1) {
            // Repeat count encoding: (count + 63) for printable range.
            $byte = chr(self::toSixelByte($pal) + 63);
            return '!' . ((string) $repeat) . $byte;
        }
        return chr(self::toSixelByte($pal) + 63);
    }

    /**
     * Emit the Sixel terminator sequence.
     *
     * Mirrors charmbracelet/x/ansi. SixelRenderer.
     */
    public static function sixelTerminator(): string
    {
        // A sixel image is a Device Control String, which is terminated by ST
        // (`ESC \`) — NOT BEL (which only closes an OSC). With BEL the terminal
        // never sees the DCS end and renders the payload as text.
        return self::ST;
    }

    /**
     * Convert an 8-bit color value to Sixel's 0-100 range.
     */
    private static function toSixelColor(int $v): int
    {
        return (int) round($v / 255 * 100);
    }

    /**
     * Encode a palette index as a Sixel byte value (0-63 range).
     */
    private static function toSixelByte(int $paletteIndex): int
    {
        return $paletteIndex & 0x3F;
    }

    /**
     * Set a DEC private mode (CSI ? <n> h).
     *
     * @param int $mode One of the DEC* constants (e.g. DECAWM, MOUSE_SGR)
     */
    public static function decSet(int $mode): string
    {
        return self::CSI . '?' . $mode . 'h';
    }

    /**
     * Reset a DEC private mode (CSI ? <n> l).
     *
     * @param int $mode One of the DEC* constants (e.g. DECAWM, MOUSE_SGR)
     */
    public static function decReset(int $mode): string
    {
        return self::CSI . '?' . $mode . 'l';
    }

    /**
     * Wrap a payload in a DCS (Device Control String) sequence.
     *
     * Format: ESC P … ESC \
     */
    public static function dcs(string $payload): string
    {
        return self::DCS . $payload . self::ST;
    }

    /**
     * Wrap a payload in an APC (Application Program Control) sequence.
     *
     * Format: ESC _ … ESC \
     */
    public static function apc(string $payload): string
    {
        return self::APC . $payload . self::ST;
    }

    /**
     * Wrap a payload in a PM (Privacy Message) sequence.
     *
     * Format: ESC ^ … ESC \
     */
    public static function pm(string $payload): string
    {
        return self::PM . $payload . self::ST;
    }
}
