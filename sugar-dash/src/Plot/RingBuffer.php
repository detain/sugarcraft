<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot;

/**
 * Indexed ring buffer for time-series data.
 *
 * O(1) push operation - appends value and overwrites oldest when full.
 * toArray() returns values in chronological order (oldest first).
 *
 * Mirrors Homedash ring buffer but with O(1) push instead of O(n) slice-shift.
 */
final class RingBuffer
{
    /** @var (float|null)[] */
    private array $data;
    private int $size;
    private int $index = 0;
    private int $count = 0;

    /**
     * @param int $size Maximum number of values to store (default: 60)
     */
    public function __construct(int $size = 60)
    {
        $this->size = $size;
        $this->data = array_fill(0, $size, null);
    }

    /**
     * Push a value onto the ring buffer.
     *
     * If the buffer is full, the oldest value is overwritten.
     */
    public function push(float $value): void
    {
        $this->data[$this->index] = $value;
        $this->index = ($this->index + 1) % $this->size;
        $this->count = min($this->count + 1, $this->size);
    }

    /**
     * Check if the buffer is empty.
     */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /**
     * Check if the buffer is full.
     */
    public function isFull(): bool
    {
        return $this->count >= $this->size;
    }

    /**
     * Get the current number of values in the buffer.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Get the maximum capacity of the buffer.
     */
    public function capacity(): int
    {
        return $this->size;
    }

    /**
     * Get all values in chronological order (oldest first).
     *
     * @return list<float|null>
     */
    public function toArray(): array
    {
        if ($this->count === 0) {
            return [];
        }

        if ($this->count < $this->size) {
            return array_slice($this->data, 0, $this->count);
        }

        // Full buffer - iterate from (index - count + size) % size to get oldest first
        $result = [];
        $startIdx = ($this->index - $this->count + $this->size) % $this->size;
        for ($i = 0; $i < $this->count; $i++) {
            $idx = ($startIdx + $i) % $this->size;
            $result[] = $this->data[$idx];
        }

        return $result;
    }

    /**
     * Get the most recent value (newest).
     *
     * Returns null if buffer is empty.
     */
    public function latest(): ?float
    {
        if ($this->count === 0) {
            return null;
        }

        $latestIndex = ($this->index - 1 + $this->size) % $this->size;
        return $this->data[$latestIndex];
    }

    /**
     * Get the oldest value in the buffer.
     *
     * Returns null if buffer is empty.
     */
    public function oldest(): ?float
    {
        if ($this->count === 0) {
            return null;
        }

        if ($this->count < $this->size) {
            return $this->data[0];
        }

        return $this->data[$this->index];
    }

    /**
     * Clear all values from the buffer.
     */
    public function clear(): void
    {
        $this->data = array_fill(0, $this->size, null);
        $this->index = 0;
        $this->count = 0;
    }
}
