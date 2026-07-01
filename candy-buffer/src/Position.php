<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A 2-D coordinate in the buffer grid.
 *
 * Negative coordinates may be used for relative offsets (e.g., anchoring
 * a region at (-1, -1) to mean "one cell before the left/top edge").
 *
 * @readonly
 */
final class Position implements \JsonSerializable
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

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{col: int, row: int}
     */
    public function __serialize(): array
    {
        return ['col' => $this->col, 'row' => $this->row];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * @param array{col: int, row: int} $data
     */
    public function __unserialize(array $data): void
    {
        // Reconstruct via constructor to properly initialize readonly promoted properties.
        $instance = new self($data['col'], $data['row']);
        $this->col = $instance->col;
        $this->row = $instance->row;
    }

    /**
     * JSON serialization support.
     *
     * @return array{col: int, row: int}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
