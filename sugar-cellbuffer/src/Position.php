<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

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
        /**
         * @deprecated the accessor is the canonical read surface (AGENTS.md
         * bare-accessor house law); the property stays published for
         * byte-compat with existing consumers. Use {@see self::col()}.
         */
        public readonly int $col,
        /** @deprecated use {@see self::row()} — canonical read surface. */
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
     * Value equality — the same coordinate spelled by a different call
     * site is the same place (consumer idiom: "did the selection move?").
     */
    public function equals(self $other): bool
    {
        return $this->col === $other->col() && $this->row === $other->row();
    }

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
        // Direct initialization is legal for readonly properties inside
        // __unserialize (the object is fresh); no throwaway instance needed.
        // Both keys are required: the silent `(int)`-cast of a missing key
        // into the origin (0,0) was a zero-cast a named refusal retires.
        $this->col = (int) self::requireKey($data, 'col', 'Position');
        $this->row = (int) self::requireKey($data, 'row', 'Position');
    }

    /**
     * Shape guard shared by the family's __unserialize hooks: missing keys
     * are named refusals, never warnings or silent zero-casts.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \InvalidArgumentException naming the payload and the key
     */
    private static function requireKey(array $data, string $key, string $payload): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new \InvalidArgumentException("{$payload} payload missing key '{$key}'");
        }

        return $data[$key];
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
