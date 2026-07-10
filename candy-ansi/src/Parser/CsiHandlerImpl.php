<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Minimal CSI handler stub for the ANSI parser.
 *
 * This is a self-contained implementation that satisfies the CsiHandler
 * interface without depending on terminal-specific state (Cell, CellGrid,
 * Cursor, Theme). Those dependencies will be wired in step-12 when
 * candy-vt migrates onto candy-ansi.
 *
 * Mirrors charmbracelet/x/ansi.CsiHandler
 */
final class CsiHandlerImpl implements CsiHandler
{
    public function printable(string $byte): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function cuu(int $count = 1): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function cud(int $count = 1): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function cuf(int $count = 1): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function cub(int $count = 1): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function cup(int $row, int $col): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function hvp(int $row, int $col): void
    {
        // No-op: requires Cursor from terminal state (identical to cup()).
    }

    public function sgr(array $params): void
    {
        // No-op: requires theme-aware color/attribute tracking.
    }

    public function ed(int $mode = 0): void
    {
        // No-op: requires CellGrid from terminal state.
    }

    public function el(int $mode = 0): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function decset(int $mode, int $prefix = 0): void
    {
        // No-op: requires cursor visibility state.
    }

    public function decrst(int $mode, int $prefix = 0): void
    {
        // No-op: requires cursor visibility state.
    }

    public function decstbm(int $top, int $bottom): void
    {
        // No-op: requires scroll region tracking.
    }

    public function tbc(int $mode = 0): void
    {
        // No-op: tab state not needed in minimal stub.
    }

    public function cbt(int $count = 1): void
    {
        // No-op: requires Cursor + tab stop table from terminal state.
    }

    public function cht(int $count = 1): void
    {
        // No-op: requires Cursor + tab stop table from terminal state.
    }

    public function cr(): void
    {
        // No-op: requires Cursor from terminal state.
    }

    public function lf(): void
    {
        // No-op: requires Cursor + scroll region from terminal state.
    }

    public function su(int $count = 1): void
    {
        // No-op: requires CellGrid + scroll region from terminal state.
    }

    public function sd(int $count = 1): void
    {
        // No-op: requires CellGrid + scroll region from terminal state.
    }

    public function il(int $count = 1): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function dl(int $count = 1): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function ich(int $count = 1): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function dch(int $count = 1): void
    {
        // No-op: requires CellGrid + Cursor from terminal state.
    }

    public function rep(int $count = 1): void
    {
        // No-op: requires last-graphic-char tracking from terminal state.
    }

    public function scosc(): void
    {
        // No-op: requires saved-cursor state from terminal state.
    }

    public function scorc(): void
    {
        // No-op: requires saved-cursor state from terminal state.
    }

    public function gridRows(): int
    {
        return 0;
    }

    public function gridCols(): int
    {
        return 0;
    }
}
