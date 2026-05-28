<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A 2-D coordinate in the buffer grid.
 *
 * @readonly
 */
final class Position
{
    public function __construct(
        public readonly int $col,
        public readonly int $row,
    ) {}

    public static function new(int $col, int $row): self
    {
        return new self($col, $row);
    }

    /** Column (0-based, horizontal axis). */
    public function col(): int { return $this->col; }

    /** Row (0-based, vertical axis). */
    public function row(): int { return $this->row; }
}
