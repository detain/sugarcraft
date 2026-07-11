<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Stateful scan-then-get fluent API for mouse hit-testing.
 *
 * Own your own Scanner instance — no global Manager wiring required.
 *
 * Usage:
 *   $scanner = Scanner::new()->scan($rendered);
 *   $zone = $scanner->get('button-1');      // by id
 *   $zone = $scanner->hit($col, $row);       // by coordinates
 *
 * Mirrors bubblezone's Scanner type (a Builder-pattern Manager replacement).
 */
final class Scanner
{
    /**
     * Grid quantisation for the {@see hit()} spatial index — each bucket
     * covers a GRID_BUCKET × GRID_BUCKET block of terminal cells.
     */
    private const GRID_BUCKET = 32;

    /** @var array<string, Zone> */
    private array $zones = [];

    /**
     * Lazily-built bucket index: "bx:by" => list of zones (in insertion
     * order) whose bounding box overlaps that grid bucket.  null until the
     * first {@see hit()} after a {@see scan()}/{@see clear()}.
     *
     * @var array<string, list<Zone>>|null
     */
    private ?array $grid = null;

    /**
     * Factory — creates an empty scanner ready for scan().
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Parse zone sentinels from $rendered, populate the zone registry,
     * and return $this for chaining.
     *
     * @param string $rendered The rendered string containing zone sentinels.
     * @param int|null $width  Optional terminal viewport width.  When set,
     *                         zone end columns are clamped to this value.
     */
    public function scan(string $rendered, ?int $width = null): self
    {
        $parser = new Scan();
        $this->zones = $parser->parse($rendered, $width);
        $this->grid = null; // invalidate the spatial index — zones changed.
        return $this;
    }

    /**
     * Return the zone with the given id, or null if not found.
     */
    public function get(string $id): ?Zone
    {
        return $this->zones[$id] ?? null;
    }

    /**
     * Return all scanned zones keyed by id.
     *
     * @return array<string, Zone>
     */
    public function all(): array
    {
        return $this->zones;
    }

    /**
     * Return all zones whose id starts with $prefix.
     *
     * @return array<string, Zone>
     */
    public function prefixed(string $prefix): array
    {
        $result = [];
        foreach ($this->zones as $id => $zone) {
            if (str_starts_with($id, $prefix)) {
                $result[$id] = $zone;
            }
        }
        return $result;
    }

    /**
     * Reverse-lookup: return the first zone whose bounding box contains
     * (col, row), or null if no zone matches.
     *
     * Backed by a lazily-built grid-bucket index: zones are bucketed by the
     * GRID_BUCKET × GRID_BUCKET blocks their bbox overlaps, so a lookup only
     * scans the (usually tiny) candidate list in the queried bucket instead of
     * every zone — sub-linear for the dense table/list/grid UIs bubblezone
     * targets.  The index is built once on the first call after a scan and
     * reused until the zone set changes.
     *
     * "First zone" is preserved verbatim: buckets are populated in zone
     * insertion order, so among overlapping zones the earliest-registered
     * still wins — identical to the old linear scan.
     *
     * @param int $col Terminal column (1-based).
     * @param int $row Terminal row (1-based).
     */
    public function hit(int $col, int $row): ?Zone
    {
        if ($this->zones === []) {
            return null;
        }
        $this->grid ??= $this->buildGrid();

        $key = self::bucket($col) . ':' . self::bucket($row);
        foreach ($this->grid[$key] ?? [] as $zone) {
            if ($col >= $zone->startCol && $col <= $zone->endCol
                && $row >= $zone->startRow && $row <= $zone->endRow
            ) {
                return $zone;
            }
        }
        return null;
    }

    /**
     * Bucket coordinate for a 1-based terminal column/row.
     */
    private static function bucket(int $coord): int
    {
        return intdiv($coord - 1, self::GRID_BUCKET);
    }

    /**
     * Populate the grid index from the current zone set, iterating in
     * insertion order so each bucket's candidate list preserves it.
     *
     * @return array<string, list<Zone>>
     */
    private function buildGrid(): array
    {
        $grid = [];
        foreach ($this->zones as $zone) {
            $bxStart = self::bucket($zone->startCol);
            $bxEnd   = self::bucket($zone->endCol);
            $byStart = self::bucket($zone->startRow);
            $byEnd   = self::bucket($zone->endRow);
            for ($by = $byStart; $by <= $byEnd; $by++) {
                for ($bx = $bxStart; $bx <= $bxEnd; $bx++) {
                    $grid[$bx . ':' . $by][] = $zone;
                }
            }
        }
        return $grid;
    }

    /**
     * Clear all recorded zones.
     */
    public function clear(): void
    {
        $this->zones = [];
        $this->grid = null;
    }
}
