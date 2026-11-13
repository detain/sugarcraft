<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

/**
 * Abstract base for all buffer-diff operations.
 *
 * Each op encodes one minimal terminal-state transition (move cursor,
 * set cell, erase run, repeat run, set style, set hyperlink).
 *
 * The operation map follows ratatui's Buffer::diff, rendered onto the
 * xterm control sequences for ECH / REP / CUP / SGR / OSC 8.
 */
abstract class DiffOp
{
}
