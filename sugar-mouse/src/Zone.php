<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * A rectangular mouse-interactive zone discovered by {@see Scanner::scan()}.
 * Coordinates are 1-based terminal cells, matching the coordinate space of
 * {@see MouseEvent::$x} / {@see MouseEvent::$y}.
 *
 * The zone is the smallest axis-aligned rectangle that contains every cell
 * occupied by the marked content.  When the marked content spans multiple
 * rows, the zone rectangle may include cells that were never part of the
 * marked content (e.g. a zone opened at column 3 covering "AA\n……BB" spans
 * col 3–8, row 1–2: cells 5–8 of row 1 and 3–4 of row 2 sit inside the
 * bounding box although no marked glyph was ever painted there).
 * Use {@see inBounds()} with awareness that it tests a bounding box, not the
 * exact cell set.
 *
 * Mirrors bubblezone's Zone struct.
 */
final class Zone
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(string $id, int $startCol, int $startRow, int $endCol, int $endRow): self
    {
        return new self($id, $startCol, $startRow, $endCol, $endRow);
    }


    /**
     * @param string $id      Zone identifier — matches the id passed to {@see Mark::wrap()}.
     * @param int    $startCol Leftmost column (1-based).
     * @param int    $startRow Topmost row (1-based).
     * @param int    $endCol   Rightmost column (1-based, inclusive).
     * @param int    $endRow  Lowest row (1-based, inclusive).
     */
    public function __construct(
        public readonly string $id,
        public readonly int $startCol,
        public readonly int $startRow,
        public readonly int $endCol,
        public readonly int $endRow,
    ) {}

    /**
     * Check whether a mouse event's coordinates fall inside this zone.
     *
     * Note: for multi-row zones this tests the full bounding-box rectangle,
     * not just the cells that were explicitly marked.  An interior cell that
     * was never part of the marked content may still return true.
     */
    public function inBounds(MouseEvent $event): bool
    {
        return $event->x >= $this->startCol && $event->x <= $this->endCol
            && $event->y >= $this->startRow && $event->y <= $this->endRow;
    }

    /**
     * Mouse position relative to the zone's top-left, 0-based.
     * Returns negative values when the mouse is outside the zone.
     *
     * Col-first, matching {@see Scanner::hit($col, $row)} and
     * `Manager::zoneAt($col, $row)` — the row-first islands are Selection
     * and SelectionRange only (see README's Coordinate order section).
     *
     * @return array{0:int,1:int} [col, row]
     */
    public function pos(MouseEvent $event): array
    {
        return [$event->x - $this->startCol, $event->y - $this->startRow];
    }

    public function width(): int  { return $this->endCol - $this->startCol + 1; }
    public function height(): int { return $this->endRow - $this->startRow + 1; }

    /**
     * True when the zone has never had its bounds set — equivalent to a
     * zero-valued struct from bubblezone's Get().
     *
     * This is an upstream-parity method only.  Scanned zones from
     * {@see Scanner::scan()} are always 1-based, so a real scanned zone
     * never has isZero() === true.  The only way to get a zero-valued zone
     * is to construct one manually (e.g. as a sentinel in tests or when
     * a lookup returns no zone).  Callers can use this to distinguish
     * "zone without bounds" from "no zone found" (where {@see Scanner::get()}
     * returns null directly).
     */
    public function isZero(): bool
    {
        return $this->startCol === 0
            && $this->startRow === 0
            && $this->endCol === 0
            && $this->endRow === 0;
    }
}
