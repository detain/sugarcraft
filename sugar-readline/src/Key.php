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
 * The set covers editing and navigation keys; it is not exhaustive of every
 * key a terminal can emit (function keys arrive as 'f1'–'f12', alt chords as
 * 'alt_<key>'; see {@see Readline::onKey()} for the full name shapes).
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
    public const CtrlC     = 'ctrl_c';
    public const CtrlG     = 'ctrl_g';
    public const CtrlK     = 'ctrl_k';
    public const CtrlR     = 'ctrl_r';
    public const CtrlS     = 'ctrl_s';
    public const CtrlU     = 'ctrl_u';
    public const CtrlW     = 'ctrl_w';
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

        return $key;
    }

    private function __construct()
    {
    }
}
