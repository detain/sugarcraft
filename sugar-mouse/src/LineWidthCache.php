<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Remembers the painted width of frame lines that carry no zone markup, so
 * re-scanning a frame whose lines did not change skips the per-cluster walk
 * for them.
 *
 * {@see Scan::parse()} reads it when one is passed; {@see Scanner} and
 * {@see \SugarCraft\Mouse\Zones\Manager} each own one, so a TUI that re-scans
 * every render pays the grapheme walk only for lines that changed (a chat
 * transcript appends at the bottom; everything above is byte-identical).
 *
 * Only *inert* lines are cached: no sentinel byte pair (U+E000..), no lone
 * CR, and no DCS / SOS / PM / APC introducer (those strings may run past the
 * line end). Within such a line the scanner's state changes only by the width
 * it paints, so its width is a pure function of its bytes and the cached value
 * is exactly what the walk would have produced.
 *
 * Generational, so memory stays bounded to about two frames' worth of lines:
 * {@see beginFrame()} retires the previous frame's entries, and a line seen
 * again is carried into the new generation on lookup.
 *
 * Mutable by design (it is a cache, like the {@see Scanner} that owns it);
 * share one only between scans of the same logical screen.
 */
final class LineWidthCache
{
    /** Lines at most this long are cached; longer ones are walked every time. */
    public const MAX_LINE_BYTES = 4096;

    /** @var array<string, int> */
    private array $current = [];

    /** @var array<string, int> */
    private array $previous = [];

    private int $hits = 0;

    private int $misses = 0;

    public static function new(): self
    {
        return new self();
    }

    /**
     * Start a new frame: entries not looked up since the previous
     * beginFrame() are dropped at the next one.
     */
    public function beginFrame(): void
    {
        $this->previous = $this->current;
        $this->current = [];
    }

    /** The cached width of $line, or null when it is not cached. */
    public function lookup(string $line): ?int
    {
        $width = $this->current[$line] ?? $this->previous[$line] ?? null;
        if ($width === null) {
            $this->misses++;
            return null;
        }
        $this->hits++;
        $this->current[$line] = $width;
        return $width;
    }

    /** Record the width the walk measured for an inert $line. */
    public function store(string $line, int $width): void
    {
        if (strlen($line) <= self::MAX_LINE_BYTES) {
            $this->current[$line] = $width;
        }
    }

    /** Lookups answered from the cache since construction. */
    public function hits(): int
    {
        return $this->hits;
    }

    /** Lookups the cache could not answer since construction. */
    public function misses(): int
    {
        return $this->misses;
    }

    /** Lines held across both generations. */
    public function size(): int
    {
        return \count($this->current + $this->previous);
    }

    /** Forget every cached line. */
    public function clear(): void
    {
        $this->current = [];
        $this->previous = [];
    }
}
