<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Screen;

use SugarCraft\Vt\Cell;

/**
 * Ring buffer that stores rows scrolled off the top of the screen.
 *
 * Each slot holds a complete row: `array<int, Cell>` — matching the
 * representation used internally by Buffer's grid.
 *
 * Capacity is fixed at construction. Once full, new pushes overwrite
 * the oldest entry. Iteration yields rows from oldest to newest.
 */
final class Scrollback
{
    /** @var array<int, array<int, Cell>|null> */
    private array $rows;

    /** Next write position (0-indexed into $rows). */
    private int $head = 0;

    /** Oldest valid entry position (0-indexed into $rows). */
    private int $tail = 0;

    /** Number of valid entries currently in the buffer. */
    private int $count = 0;

    /**
     * @param int $maxSize ring capacity; MUST be >= 1.
     *
     * @throws \InvalidArgumentException when $maxSize < 1.
     *
     * The invariant lives here because every ring index is computed
     * `% $this->maxSize` (push/all/at) — a zero capacity is a latent
     * DivisionByZeroError on first push, a negative one an opaque
     * array_fill() ValueError. Throw rather than clamp: this is a
     * programmer error with no coherent fallback, and the sibling entry
     * point `Terminal::withScrollbackSize()` (Terminal.php:511) already
     * rejects the same input with InvalidArgumentException — clamping
     * here would make the two paths contradict each other. No upstream
     * Go counterpart: port-side guard.
     */
    public function __construct(
        private readonly int $maxSize = 1000,
    ) {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('maxSize must be >= 1');
        }
        $this->rows = array_fill(0, $maxSize, null);
    }

    /**
     * Append a row to the buffer.
     *
     * If the buffer is not yet full, rows are appended in insertion order.
     * If full, the oldest row is silently overwritten.
     *
     * @param array<int, Cell> $row
     */
    public function push(array $row): void
    {
        if ($this->count < $this->maxSize) {
            $this->rows[$this->head] = $row;
            $this->head = ($this->head + 1) % $this->maxSize;
            $this->count++;
        } else {
            // Full: overwrite at head, then advance both head and tail
            $this->rows[$this->head] = $row;
            $this->head = ($this->head + 1) % $this->maxSize;
            $this->tail = ($this->tail + 1) % $this->maxSize;
        }
    }

    /**
     * Return all rows from oldest to newest.
     *
     * @return array<int, array<int, Cell>>
     */
    public function all(): array
    {
        if ($this->count === 0) {
            return [];
        }

        // When full, entries run from tail around to head-1.
        // When not full, entries run from 0 to head-1.
        $start = $this->count < $this->maxSize ? 0 : $this->tail;

        $result = [];
        for ($i = 0; $i < $this->count; $i++) {
            $idx = ($start + $i) % $this->maxSize;
            $result[] = $this->rows[$idx];
        }

        return $result;
    }

    /**
     * Return the row at the given offset from the oldest entry (0 = oldest).
     *
     * @return array<int, Cell>|null
     */
    public function at(int $offset): ?array
    {
        if ($offset < 0 || $offset >= $this->count) {
            return null;
        }

        // When not full, entries start at 0. When full, entries start at tail.
        $start = $this->count < $this->maxSize ? 0 : $this->tail;
        $idx = ($start + $offset) % $this->maxSize;

        return $this->rows[$idx];
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Drop every stored row (ED 3 — "clear scrollback", xterm ctlseqs
     * CSI 3 J). The ring keeps its capacity; head/tail/count rewind to a
     * pristine state so subsequent pushes append from the start again.
     */
    public function clear(): void
    {
        $this->rows = array_fill(0, $this->maxSize, null);
        $this->head = 0;
        $this->tail = 0;
        $this->count = 0;
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }
}
