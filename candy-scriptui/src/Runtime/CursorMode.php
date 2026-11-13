<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * The `--cursor-mode` vocabulary shared by the interactive text commands
 * (`input`, `write`). gum accepts exactly blink | static | hidden and
 * distinguishes all three: the two visible arms set the DECSCUSR cursor
 * style (blink = blinking block `ESC[1 q`, static = steady block
 * `ESC[2 q`, mirroring charmbracelet's cursor.Blink/cursor.Steady which
 * program the same pair), while `hidden` asks the Program to hide the
 * cursor via {@see \SugarCraft\Core\ProgramOptions::$hideCursor} (DEC
 * private ?25l — a different mechanism, so the arms never collide).
 * scriptui-73 (finding 099): before this row blink and static painted
 * byte-identical frames and the documented distinction was hollow.
 * Emission and the `ESC[0 q` exit reset live in
 * {@see InteractiveRuntime::emitCursorStyle()}, gated on dumb TERM like
 * every other DEC byte this app paints.
 */
final class CursorMode
{
    /** @var list<string> */
    public const KNOWN = ['blink', 'static', 'hidden'];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN, true);
    }

    public static function hidesCursor(string $mode): bool
    {
        return $mode === 'hidden';
    }

    /**
     * The DECSCUSR start-up sequence that gives the mode its visible
     * meaning, or null when the arm needs no style byte: `hidden` is
     * owned by the hide mechanism, and an unknown mode must not paint.
     */
    public static function styleSequence(string $mode): ?string
    {
        return match ($mode) {
            'blink'  => "\e[1 q",
            'static' => "\e[2 q",
            default  => null,
        };
    }

    /**
     * Terminal-default restore, emitted on exit whenever a style
     * sequence was painted: a scripted blink/steady cursor must not
     * outlive the program into the user's shell.
     */
    public static function resetSequence(): string
    {
        return "\e[0 q";
    }
}
