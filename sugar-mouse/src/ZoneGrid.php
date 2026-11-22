<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Mouse\Zones\Zone as ZonesZone;

/**
 * The bucket-grid spatial index and the package's ONE hit rule.
 *
 * Both hit-testing layers resolve a cell through this class, so they can no
 * longer disagree about who owns it: the primitive {@see Scanner::hit()} and
 * the Zones-layer {@see \SugarCraft\Mouse\Zones\Manager::zoneAt()} /
 * `anyInBounds()` / `allInBounds()` all read {@see smallestAt()} or
 * {@see allAt()}.
 *
 * **The hit rule.** Among the zones whose bounding box covers the cell, the
 * one with the SMALLEST area wins (nested components: the innermost button
 * beats the panel around it). Equal areas go to the zone registered first
 * (the order of the iterable handed to {@see of()}). bubblezone itself has
 * no ordering rule beyond map iteration; this is the house rule, chosen so a
 * click on a nested control never lands on its container.
 *
 * **The index.** Zones are bucketed by the {@see BUCKET} × {@see BUCKET}
 * blocks their box overlaps, so a lookup scans only the (usually tiny)
 * candidate list of one bucket instead of every zone — sub-linear for the
 * dense table, list and grid UIs a frame of zones describes. A grid is a
 * frozen snapshot: callers build one per zone set and drop it when the set
 * changes.
 *
 * A zone whose box overlaps more than {@see MAX_ZONE_BUCKETS} buckets is
 * kept on a short "wide" list that every lookup scans instead, so building
 * the grid costs at most that many entries per zone. Without the cap the
 * fill scaled with box AREA: one zone 25000 cells on a side would write
 * ~611k bucket keys and exhaust a 128M memory limit on the first click.
 *
 * @template T of Zone|ZonesZone
 */
final class ZoneGrid
{
    /** Cells per bucket side. */
    public const BUCKET = 32;

    /**
     * Most buckets one zone may occupy; a larger box goes on {@see $wide}.
     * 256 buckets cover a 512 × 512 cell box, past any real terminal.
     */
    public const MAX_ZONE_BUCKETS = 256;

    /**
     * Bucket key "bx:by" => indexes into {@see $zones}, ascending (so
     * registration order survives inside every bucket).
     *
     * @var array<string, list<int>>
     */
    private array $buckets = [];

    /**
     * Indexes of zones too large to bucket, ascending; checked on every
     * lookup.
     *
     * @var list<int>
     */
    private array $wide = [];

    /**
     * @param list<T> $zones registration order
     */
    private function __construct(private readonly array $zones)
    {
        foreach ($zones as $index => $zone) {
            $bxStart = self::bucket($zone->startCol);
            $byStart = self::bucket($zone->startRow);
            $bxEnd = self::bucket($zone->endCol);
            $byEnd = self::bucket($zone->endRow);
            if (($bxEnd - $bxStart + 1) * ($byEnd - $byStart + 1) > self::MAX_ZONE_BUCKETS) {
                $this->wide[] = $index;
                continue;
            }
            for ($by = $byStart; $by <= $byEnd; $by++) {
                for ($bx = $bxStart; $bx <= $bxEnd; $bx++) {
                    $this->buckets[$bx . ':' . $by][] = $index;
                }
            }
        }
    }

    /**
     * Index a zone set. Iteration order is registration order — the
     * equal-area tie-break.
     *
     * @template Z of Zone|ZonesZone
     * @param iterable<Z> $zones
     * @return self<Z>
     */
    public static function of(iterable $zones): self
    {
        $list = [];
        foreach ($zones as $zone) {
            $list[] = $zone;
        }
        return new self($list);
    }

    /** Number of indexed zones. */
    public function count(): int
    {
        return \count($this->zones);
    }

    /**
     * The zone that owns cell ($col, $row), 1-based: the smallest covering
     * area, ties to the first registered. Null when no zone covers it.
     *
     * @return T|null
     */
    public function smallestAt(int $col, int $row): Zone|ZonesZone|null
    {
        $hit = null;
        $smallest = PHP_INT_MAX;
        foreach ($this->candidates($col, $row) as $zone) {
            $area = self::area($zone);
            // Strict `<`: candidates arrive in registration order, so the
            // first of several equal areas is kept.
            if ($area < $smallest) {
                $smallest = $area;
                $hit = $zone;
            }
        }
        return $hit;
    }

    /**
     * Every zone covering cell ($col, $row), innermost first: ascending
     * area, equal areas in registration order (PHP's sort is stable).
     *
     * @return list<T>
     */
    public function allAt(int $col, int $row): array
    {
        $hits = $this->candidates($col, $row);
        usort($hits, static fn (Zone|ZonesZone $a, Zone|ZonesZone $b): int => self::area($a) <=> self::area($b));
        return $hits;
    }

    /**
     * Zones of the cell's bucket that really cover the cell, in
     * registration order.
     *
     * @return list<T>
     */
    private function candidates(int $col, int $row): array
    {
        $indexes = $this->buckets[self::bucket($col) . ':' . self::bucket($row)] ?? [];
        if ($this->wide !== []) {
            // Both lists ascend; re-sort the union so candidates keep
            // registration order (the equal-area tie-break).
            $indexes = [...$indexes, ...$this->wide];
            sort($indexes);
        }
        $hits = [];
        foreach ($indexes as $index) {
            $zone = $this->zones[$index];
            if ($col >= $zone->startCol && $col <= $zone->endCol
                && $row >= $zone->startRow && $row <= $zone->endRow
            ) {
                $hits[] = $zone;
            }
        }
        return $hits;
    }

    private static function area(Zone|ZonesZone $zone): int
    {
        return ($zone->endCol - $zone->startCol + 1) * ($zone->endRow - $zone->startRow + 1);
    }

    /**
     * Bucket coordinate for a 1-based terminal column/row.
     *
     * Off-grid coordinates (< 1, reachable from hostile input) map to bucket
     * -1, which no scanned zone can occupy: zones register at columns and
     * rows >= 1. The guard also keeps `$coord - 1` an int — PHP_INT_MIN - 1
     * would overflow to float and TypeError inside intdiv().
     */
    private static function bucket(int $coord): int
    {
        if ($coord < 1) {
            return -1;
        }
        return intdiv($coord - 1, self::BUCKET);
    }
}
