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
    /** Move cursor to ($col, $row) before other ops. */
    public const TYPE_MOVE_CURSOR = 'move_cursor';

    /** Write one or more cells starting at current cursor position. */
    public const TYPE_SET_CELL = 'set_cell';

    /** Erase N characters starting at current cursor position (ECH). */
    public const TYPE_ERASE_RUN = 'erase_run';

    /** Repeat the preceding character N times (REP). */
    public const TYPE_REPEAT_RUN = 'repeat_run';

    /** Transition SGR style before next cell write. */
    public const TYPE_SET_STYLE = 'set_style';

    /** Open or close OSC 8 hyperlink before next cell write. */
    public const TYPE_SET_HYPERLINK = 'set_hyperlink';
}
