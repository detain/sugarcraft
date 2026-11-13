<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

/**
 * Move cursor to ($col, $row) using CUP (Cursor Position) sequence.
 *
 * CUP is emitted as \x1b[row;colH where row and col are 1-based.
 *
 * @readonly
 */
final class MoveCursorOp extends DiffOp
{
    public function __construct(
        /**
         * @deprecated the accessors are the canonical read surface (AGENTS.md
         * bare-accessor house law); these stay published readonly for
         * byte-compat with hand-built op streams. Use {@see self::col()}.
         */
        public readonly int $col,
        /** @deprecated use {@see self::row()} — canonical read surface. */
        public readonly int $row,
    ) {}

    /** Destination column (0-based; CUP emits it 1-based). */
    public function col(): int { return $this->col; }

    /** Destination row (0-based; CUP emits it 1-based). */
    public function row(): int { return $this->row; }

    /**
     * @return array{col: int, row: int}
     */
    public function info(): array
    {
        return ['col' => $this->col, 'row' => $this->row];
    }
}
