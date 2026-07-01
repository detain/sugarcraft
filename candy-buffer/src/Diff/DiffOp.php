<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

/**
 * Abstract base for all buffer-diff operations.
 *
 * Each op encodes one minimal terminal-state transition (move cursor,
 * set cell, erase run, repeat run, set style, set hyperlink).
 *
 * Mirrors ratatui's Buffer::diff operation map and the xterm control
 * sequences for ECH / REP / ICH / DCH / CUP / SGR.
 *
 * @readonly
 */
abstract class DiffOp
{
    /**
     * Historical artifacts — these constants predate the op class
     * split and are no longer referenced in the current implementation.
     * Kept for API compatibility only.
     *
     * @deprecated Remove in a future major version. The type strings
     *             are now encapsulated in the concrete op class names.
     */
    public const TYPE_MOVE_CURSOR = 'move_cursor';

    /** @deprecated See above */
    public const TYPE_SET_CELL = 'set_cell';

    /** @deprecated See above */
    public const TYPE_ERASE_RUN = 'erase_run';

    /** @deprecated See above */
    public const TYPE_REPEAT_RUN = 'repeat_run';

    /** @deprecated See above */
    public const TYPE_SET_STYLE = 'set_style';

    /** @deprecated See above */
    public const TYPE_SET_HYPERLINK = 'set_hyperlink';
}
