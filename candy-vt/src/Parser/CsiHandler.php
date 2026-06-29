<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

/**
 * Receives CSI (Control Sequence Introducer) dispatches from the parser.
 *
 * Empty shell for now — Phase 1c fills in the implementations.
 * The handler processes completed CSI sequences and updates terminal
 * state accordingly.
 */
interface CsiHandler
{
    /**
     * Print a printable grapheme to the current cell.
     *
     * Handles ASCII, Latin-1, and multi-byte UTF-8 runes. Width
     * awareness is applied: wide characters (e.g. CJK) occupy 2 cells
     * with a continuation cell; combining marks (width 0) attach
     * to the previous cell.
     */
    public function printable(string $grapheme): void;

    /**
     * Cursor Up — move cursor up $count rows.
     */
    public function cuu(int $count): void;

    /**
     * Cursor Down — move cursor down $count rows.
     */
    public function cud(int $count): void;

    /**
     * Cursor Forward — move cursor right $count columns.
     */
    public function cuf(int $count): void;

    /**
     * Cursor Back — move cursor left $count columns.
     */
    public function cub(int $count): void;

    /**
     * Cursor Position — move cursor to $row, $col (1-indexed).
     */
    public function cup(int $row, int $col): void;

    /**
     * Select Graphic Rendition — set text attributes from $params.
     *
     * @param list<int> $params  CSI parameter bytes; -1 means default.
     */
    public function sgr(array $params): void;

    /**
     * Erase Display — clear screen regions.
     *
     * @param int $mode  0=below, 1=above, 2=all, 3=scrollback
     */
    public function ed(int $mode): void;

    /**
     * Erase Line — clear line regions.
     *
     * @param int $mode  0=right, 1=left, 2=all
     */
    public function el(int $mode): void;

    /**
     * DECSET — DEC private mode set (prefix byte 0x3C-0x3F, e.g. '?').
     *
     * @param int        $mode  DEC mode number (e.g. 25 for cursor visible)
     * @param int        $prefix  private marker byte, 0 if none
     */
    public function decset(int $mode, int $prefix): void;

    /**
     * DECRST — DEC private mode reset.
     *
     * @param int        $mode  DEC mode number
     * @param int        $prefix  private marker byte, 0 if none
     */
    public function decrst(int $mode, int $prefix): void;

    /**
     * DECSTBM — set top and bottom scroll region margins.
     *
     * @param int $top    top margin row (1-indexed)
     * @param int $bottom bottom margin row (1-indexed)
     */
    public function decstbm(int $top, int $bottom): void;

    /**
     * TBC — tab clear.
     *
     * @param int $mode  0=clear at cursor, 3=clear all
     */
    public function tbc(int $mode): void;

    /**
     * CHT — Cursor Horizontal Tab. Moves the cursor forward $count tab stops.
     */
    public function cht(int $count = 1): void;

    /**
     * CBT — Cursor Backward Tab. Moves the cursor back $count tab stops.
     */
    public function cbt(int $count = 1): void;

    /**
     * CR — carriage return. Move cursor to column 0 (row unchanged).
     */
    public function cr(): void;

    /**
     * LF — line feed. Advance cursor down one row, scrolling if at
     * the bottom of the scroll region. VT and FF behave identically.
     */
    public function lf(): void;

    /**
     * Number of rows in the cell grid (used as bottom-margin default).
     */
    public function gridRows(): int;
}
