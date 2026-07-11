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
}
