<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A rectangular sub-region of a Buffer, identified by its top-left
 * {@see Position} and dimensions.
 *
 * Note: zero-width or zero-height regions are edge cases — a cell at
 * the origin is contained only if the region has positive dimensions.
 * See {@see contains()} for the zero-size boundary behaviour.
 *
 * @readonly
 */
final class Region implements \JsonSerializable
{
    public function __construct(
        public readonly Position $origin,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public static function new(Position $origin, int $width, int $height): self
    {
        return new self($origin, $width, $height);
    }

    /** Top-left corner. */
    public function origin(): Position { return $this->origin; }

    /** Width in cells. */
    public function width(): int { return $this->width; }

    /** Height in cells. */
    public function height(): int { return $this->height; }

    /** Rightmost column (inclusive). */
    public function right(): int
    {
        return $this->origin->col + $this->width - 1;
    }

    /** Bottommost row (inclusive). */
    public function bottom(): int
    {
        return $this->origin->row + $this->height - 1;
    }

    /**
     * Whether a cell at ($col, $row) falls within this region.
     *
     * @see For zero-width/height edge cases, see class docblock.
     */
    public function contains(int $col, int $row): bool
    {
        return $col >= $this->origin->col
            && $col <= $this->right()
            && $row >= $this->origin->row
            && $row <= $this->bottom();
    }

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{origin: array{col: int, row: int}, width: int, height: int}
     */
    public function __serialize(): array
    {
        return [
            'origin' => $this->origin->__serialize(),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * @param array{origin: array{col: int, row: int}, width: int, height: int} $data
     */
    public function __unserialize(array $data): void
    {
        $this->origin = new Position($data['origin']['col'], $data['origin']['row']);
        $this->width = $data['width'];
        $this->height = $data['height'];
    }

    /**
     * JSON serialization support.
     *
     * @return array{origin: array{col: int, row: int}, width: int, height: int}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
