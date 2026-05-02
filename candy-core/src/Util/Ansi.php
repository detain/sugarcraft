<?php

declare(strict_types=1);

namespace CandyCore\Core\Util;

final class Ansi
{
    public const ESC = "\x1b";
    public const CSI = "\x1b[";
    public const OSC = "\x1b]";
    public const ST  = "\x1b\\";
    public const BEL = "\x07";

    public const RESET     = 0;
    public const BOLD      = 1;
    public const FAINT     = 2;
    public const ITALIC    = 3;
    public const UNDERLINE = 4;
    public const BLINK     = 5;
    public const REVERSE   = 7;
    public const CONCEAL   = 8;
    public const STRIKE    = 9;

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
            throw new \InvalidArgumentException("invalid 16-color fg code: $code");
        }
        return self::CSI . $code . 'm';
    }

    public static function bg16(int $code): string
    {
        if ($code < 40 || ($code > 47 && $code < 100) || $code > 107) {
            throw new \InvalidArgumentException("invalid 16-color bg code: $code");
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

    public static function cursorUp(int $n = 1): string    { return self::CSI . max(1, $n) . 'A'; }
    public static function cursorDown(int $n = 1): string  { return self::CSI . max(1, $n) . 'B'; }
    public static function cursorRight(int $n = 1): string { return self::CSI . max(1, $n) . 'C'; }
    public static function cursorLeft(int $n = 1): string  { return self::CSI . max(1, $n) . 'D'; }
    public static function cursorTo(int $row, int $col): string
    {
        return self::CSI . max(1, $row) . ';' . max(1, $col) . 'H';
    }
    public static function cursorHide(): string { return self::CSI . '?25l'; }
    public static function cursorShow(): string { return self::CSI . '?25h'; }
    public static function cursorSave(): string    { return self::ESC . '7'; }
    public static function cursorRestore(): string { return self::ESC . '8'; }

    public static function eraseLine(): string   { return self::CSI . '2K'; }
    public static function eraseScreen(): string { return self::CSI . '2J'; }
    public static function eraseToEnd(): string  { return self::CSI . '0J'; }

    public static function altScreenEnter(): string { return self::CSI . '?1049h'; }
    public static function altScreenLeave(): string { return self::CSI . '?1049l'; }

    /**
     * Synchronized output (DEC mode 2026). Wrap each rendered frame in
     * `syncBegin` / `syncEnd` so the terminal buffers the whole frame
     * before painting, eliminating tearing and partial-frame flashes
     * on slow terminals. Bubble Tea v2 enables this by default; we
     * follow.
     */
    public static function syncBegin(): string { return self::CSI . '?2026h'; }
    public static function syncEnd(): string   { return self::CSI . '?2026l'; }

    /**
     * Grapheme cluster mode (DEC mode 2027). Tells the terminal to
     * report widths / cursor advances per grapheme cluster instead of
     * per code point — fixes the long-standing emoji-width drift.
     * Toggle once at program startup; restore on teardown.
     */
    public static function unicodeOn(): string  { return self::CSI . '?2027h'; }
    public static function unicodeOff(): string { return self::CSI . '?2027l'; }

    public static function bracketedPasteOn(): string  { return self::CSI . '?2004h'; }
    public static function bracketedPasteOff(): string { return self::CSI . '?2004l'; }

    public static function mouseAllOn(): string   { return self::CSI . '?1000h' . self::CSI . '?1006h'; }
    public static function mouseAllOff(): string  { return self::CSI . '?1006l' . self::CSI . '?1000l'; }

    /** Cell-motion tracking: report when a button is held and the mouse moves. */
    public static function mouseCellMotionOn(): string  { return self::CSI . '?1002h' . self::CSI . '?1006h'; }
    public static function mouseCellMotionOff(): string { return self::CSI . '?1006l' . self::CSI . '?1002l'; }

    /** All-motion tracking: report every move regardless of button state. */
    public static function mouseAllMotionOn(): string  { return self::CSI . '?1003h' . self::CSI . '?1006h'; }
    public static function mouseAllMotionOff(): string { return self::CSI . '?1006l' . self::CSI . '?1003l'; }

    public static function focusReportingOn(): string  { return self::CSI . '?1004h'; }
    public static function focusReportingOff(): string { return self::CSI . '?1004l'; }

    /**
     * Ask the terminal where the cursor is. The reply comes back as a
     * CSI sequence: `ESC [ <row> ; <col> R` (DSR-CPR), parsed into a
     * {@see \CandyCore\Core\Msg\CursorPositionMsg}.
     */
    public static function requestCursorPosition(): string { return self::CSI . '6n'; }

    /**
     * Ask the terminal for its current default foreground colour. Reply
     * arrives as `OSC 10 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \CandyCore\Core\Msg\ForegroundColorMsg}.
     */
    public static function requestForegroundColor(): string { return self::OSC . '10;?' . self::BEL; }

    /**
     * Ask the terminal for its current default background colour. Reply
     * arrives as `OSC 11 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \CandyCore\Core\Msg\BackgroundColorMsg}. Useful for picking
     * a theme that contrasts the user's background.
     */
    public static function requestBackgroundColor(): string { return self::OSC . '11;?' . self::BEL; }

    /**
     * Ask the terminal for its current cursor colour. Reply arrives as
     * `OSC 12 ; rgb:RRRR/GGGG/BBBB ST|BEL` and is parsed into
     * {@see \CandyCore\Core\Msg\CursorColorMsg}.
     */
    public static function requestCursorColor(): string { return self::OSC . '12;?' . self::BEL; }

    /**
     * Ask the terminal to identify itself (XTVERSION). Reply arrives
     * as a DCS sequence: `ESC P > | <terminal name and version> ESC \`
     * (e.g. `xterm(367)` or `iTerm2 3.4.16`) and is parsed into
     * {@see \CandyCore\Core\Msg\TerminalVersionMsg}. Useful for
     * gating capabilities (sixel, kitty keyboard, etc.) on the
     * specific terminal.
     */
    public static function requestTerminalVersion(): string { return self::CSI . '>0q'; }

    /**
     * Ask the terminal whether a given mode is set (DECRQM). Reply
     * arrives as `CSI [?] <mode> ; <state> $ y` (DECRPM) and is
     * parsed into {@see \CandyCore\Core\Msg\ModeReportMsg}.
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
     * BEL|ST` and is parsed into {@see \CandyCore\Core\Msg\ClipboardMsg}.
     */
    public static function readClipboard(string $selection = 'c'): string
    {
        return self::OSC . '52;' . $selection . ';?' . self::BEL;
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
            if ($next !== '') {
                $i += 2;
                continue;
            }
            $i++;
        }
        return $out;
    }

    private static function assertByte(int $v, string $label): void
    {
        if ($v < 0 || $v > 255) {
            throw new \InvalidArgumentException("$label out of range [0,255]: $v");
        }
    }
}
