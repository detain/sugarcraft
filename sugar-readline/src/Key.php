<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Symbolic key names accepted by every prompt's handleKey().
 *
 * The symbolic names below are the canonical key language of this library:
 * prompts, modes, and handlers all speak it. Raw terminal control bytes are
 * translated into it once, at the entry points, by {@see self::normalize()} —
 * callers may feed either form and reach the same bindings.
 *
 * Constants — not an enum — so callers can interop with raw strings
 * (e.g. when forwarding from a parser that already produces names).
 * The set covers editing, navigation and the full control-letter alphabet;
 * it is not exhaustive of every key a terminal can emit (function keys
 * arrive as 'f1'–'f12', alt chords as 'alt_<key>', shifted letters as
 * 'shift_<letter>'; see {@see Readline::onKey()} for the full name shapes).
 */
final class Key
{
    public const Up        = 'up';
    public const Down      = 'down';
    public const Left      = 'left';
    public const Right     = 'right';
    public const Home      = 'home';
    public const End       = 'end';
    public const PageUp    = 'pageup';
    public const PageDown  = 'pagedown';
    public const Tab       = 'tab';
    public const Enter     = 'enter';
    public const Backspace = 'backspace';
    public const Delete    = 'delete';
    public const Space     = 'space';
    public const Escape    = 'esc';
    // The complete control-letter vocabulary (finding 074): one constant
    // per Ctrl byte, spelled exactly like {@see self::normalize()} emits it.
    // Call sites across the library speak these names instead of re-typing
    // the strings.
    public const CtrlA     = 'ctrl_a';
    public const CtrlB     = 'ctrl_b';
    public const CtrlC     = 'ctrl_c';
    public const CtrlD     = 'ctrl_d';
    public const CtrlE     = 'ctrl_e';
    public const CtrlF     = 'ctrl_f';
    public const CtrlG     = 'ctrl_g';
    public const CtrlH     = 'ctrl_h';
    public const CtrlI     = 'ctrl_i';
    public const CtrlJ     = 'ctrl_j';
    public const CtrlK     = 'ctrl_k';
    public const CtrlL     = 'ctrl_l';
    public const CtrlM     = 'ctrl_m';
    public const CtrlN     = 'ctrl_n';
    public const CtrlO     = 'ctrl_o';
    public const CtrlP     = 'ctrl_p';
    public const CtrlQ     = 'ctrl_q';
    public const CtrlR     = 'ctrl_r';
    public const CtrlS     = 'ctrl_s';
    public const CtrlT     = 'ctrl_t';
    public const CtrlU     = 'ctrl_u';
    public const CtrlV     = 'ctrl_v';
    public const CtrlW     = 'ctrl_w';
    public const CtrlX     = 'ctrl_x';
    public const CtrlY     = 'ctrl_y';
    public const CtrlZ     = 'ctrl_z';
    public const Undo      = 'undo';
    public const Redo      = 'redo';

    /**
     * Translate a raw terminal control byte (or a legacy name) into the
     * canonical symbolic name every handleKey()/onKey() surface speaks.
     *
     * Idempotent on symbolic names and printable characters: feeding an
     * already-symbolic name (or a plain character like 'x') returns it
     * unchanged, so entry points may normalize unconditionally.
     *
     * Byte mapping follows GNU readline conventions:
     * - 0x01–0x1A → 'ctrl_a' … 'ctrl_z', except the aliases terminals
     *   actually produce: 0x08 (Ctrl+H) and 0x7F both mean backspace,
     *   0x09 is tab, 0x0A/0x0D are enter.
     * - 0x1B → 'esc' (also what Ctrl+[ produces — GNU binds C-[ to ESC).
     * - 0x20 → 'space' (the name prompts bind; raw-text space insertion goes
     *   through handleChar(), which does not normalize).
     * - The name 'escape' (once emitted by older docs) → 'esc'.
     */
    public static function normalize(string $key): string
    {
        if ($key === 'escape') {
            return self::Escape;
        }

        if (strlen($key) !== 1) {
            return $key;
        }

        $ord = \ord($key);

        if ($ord >= 1 && $ord <= 26) {
            return match ($ord) {
                8   => self::Backspace, // Ctrl+H ≡ erase (GNU backward-delete-char)
                9   => self::Tab,       // Ctrl+I ≡ tab
                10, 13 => self::Enter,  // Ctrl+J / Ctrl+M ≡ newline / carriage return
                default => 'ctrl_' . \chr($ord + 96),
            };
        }

        if ($ord === 27) {
            return self::Escape;
        }

        if ($ord === 127) {
            return self::Backspace;
        }

        if ($ord === 32) {
            return self::Space; // raw space ≡ the named key every prompt binds
        }

        return $key;
    }

    private function __construct()
    {
    }
}
