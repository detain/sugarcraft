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
     * Handles CSI (ESC[...), OSC (ESC]...ST|BEL), single-char ESC sequences,
     * and lone ESCs.
     */
    public static function strip(string $s): string
    {
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c !== self::ESC) {
                $out .= $c;
                $i++;
                continue;
            }
            $next = $s[$i + 1] ?? '';
            if ($next === '[') {
                $i += 2;
                while ($i < $len) {
                    $b = ord($s[$i]);
                    $i++;
                    if ($b >= 0x40 && $b <= 0x7e) {
                        break;
                    }
                }
                continue;
            }
            if ($next === ']') {
                $i += 2;
                while ($i < $len) {
                    if ($s[$i] === self::BEL) {
                        $i++;
                        break;
                    }
                    if ($s[$i] === self::ESC && ($s[$i + 1] ?? '') === '\\') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            // Not [, not ], not empty — lone ESC (e.g. stray 0x1b).
            // Only skip the ESC itself; the following byte is processed
            // normally so we don't accidentally eat valid UTF-8 continuation
            // bytes that happen to land after an ESC (see issue: bytes like
            // \x1b followed by a continuation byte 0x80-0xbf were being
            // skipped as a pair, corrupting multi-byte characters).
            $i++;
            continue;
        }
        return $out;
    }

    private static function assertByte(int $v, string $label): void
    {
        if ($v < 0 || $v > 255) {
            throw new \InvalidArgumentException(Lang::t('ansi.component_out_of_range', ['label' => $label, 'value' => $v]));
        }
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
     * Emit a Kitty graphics protocol data chunk.
     *
     * Mirrors charmbracelet/x/ansi. KittyRenderer.
     *
     * @param string $base64  Base64-encoded data chunk
     * @param bool   $more    True if more chunks follow (sets m=1)
     */
    public static function kittyGraphicsChunk(string $base64, bool $more): string
    {
        return 'm=' . ($more ? '1' : '0') . ',' . $base64;
    }

    /**
     * Emit the Kitty graphics protocol begin sequence.
     *
     * Format: DCS q <key>=<value>,<key>=<value>,... ST
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
     * Mirrors charmbracelet/x/ansi. KittyRenderer.
     *
     * @param array<string, mixed> $opts  Key-value pairs for the begin sequence
     */
    public static function kittyGraphicsBegin(array $opts): string
    {
        $pairs = [];
        foreach ($opts as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }
        return self::DCS . 'q' . implode(',', $pairs) . self::ST;
    }

    /**
     * Emit the final end-of-transmission chunk for Kitty graphics.
     *
     * Mirrors charmbracelet/x/ansi. KittyRenderer.
     */
    public static function kittyGraphicsEnd(): string
    {
        return 'm=0' . self::ST;
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
