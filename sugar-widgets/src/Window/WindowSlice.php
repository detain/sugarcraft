<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Window;

/**
 * A half-open range `[start, end)` of row indexes: the rows a scrolled list
 * paints. `length()` is `end - start`, so `array_slice($rows, $w->start(),
 * $w->length())` is exactly the visible rows, and an empty slice has
 * `start() === end()`.
 *
 * The constructor clamps instead of throwing, like {@see Window::around()}:
 * a negative start becomes 0 and an end before the start becomes the start,
 * so every instance satisfies `0 <= start <= end`.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no windowing helper,
 * so there is no upstream Mirrors citation.
 */
final readonly class WindowSlice
{
    private int $start;

    private int $end;

    public function __construct(int $start, int $end)
    {
        $this->start = max(0, $start);
        $this->end = max($this->start, $end);
    }

    /** First visible index, inclusive. */
    public function start(): int
    {
        return $this->start;
    }

    /** One past the last visible index (exclusive). */
    public function end(): int
    {
        return $this->end;
    }

    /** Number of visible rows: `end() - start()`. */
    public function length(): int
    {
        return $this->end - $this->start;
    }
}
