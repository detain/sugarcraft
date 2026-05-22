<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Reply to a {@see \SugarCraft\Core\Cmd::requestKittyKeyboard()}. The
 * terminal answers `CSI ? <flags> u` — `$flags` is a bitfield against
 * the Kitty progressive-keyboard flag constants below.
 *
 * Flags reference (https://sw.kovidgoyal.net/kitty/keyboard-protocol/):
 *   1  Disambiguate escape codes (e.g. distinguishes `Esc` from `Alt-`)
 *   2  Report event types (key press / release / repeat)
 *   4  Report alternate keys (shifted layouts)
 *   8  Report all keys as escape codes
 *   16 Report associated text alongside each key event
 */
final class KeyboardEnhancementsMsg implements Msg
{
    public const DISAMBIGUATE        = 1;
    public const REPORT_EVENT_TYPES  = 2;
    public const REPORT_ALTERNATES   = 4;
    public const REPORT_ALL_AS_ESC   = 8;
    public const REPORT_ASSOCIATED   = 16;

    public function __construct(
        public readonly int $flags,
    ) {
    }

    /** True if every flag in `$mask` is set. */
    public function has(int $mask): bool
    {
        return ($this->flags & $mask) === $mask;
    }
}
