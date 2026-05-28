<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * A rectangular mouse-interactive zone discovered by {@see Scanner::scan()}.
 * Coordinates are 1-based terminal cells, matching the coordinate space of
 * {@see MouseEvent::$x} / {@see MouseEvent::$y}.
 *
 * The zone is the smallest axis-aligned rectangle that contains every cell
 * occupied by the marked content.
 *
 * Mirrors bubblezone's Zone struct.
 */
final class Zone
{
    /**
     * @param string $id      Zone identifier — matches the id passed to {@see Mark::wrap()}.
     * @param int    $startCol Leftmost column (1-based).
     * @param int    $startRow Topmost row (1-based).
     * @param int    $endCol   Rightmost column (1-based, inclusive).
     * @param int    $endRow  Bottommost row (1-based, inclusive).
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
     * zero-valued struct from bubblezone's Get(). Callers can use this to
     * distinguish "no zone found" from "zone without bounds".
     */
    public function isZero(): bool
    {
        return $this->startCol === 0
            && $this->startRow === 0
            && $this->endCol === 0
            && $this->endRow === 0;
    }
}
