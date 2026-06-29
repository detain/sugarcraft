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
    /** @var array<string, Zone> */
    private array $zones = [];

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
     * (col, row), or null if no zone matches. O(n) scan of the zone list —
     * adequate for n < 100; callers with many zones should sort by area
     * and consider a spatial index.
     */
    public function hit(int $col, int $row): ?Zone
    {
        foreach ($this->zones as $zone) {
            if ($col >= $zone->startCol && $col <= $zone->endCol
                && $row >= $zone->startRow && $row <= $zone->endRow
            ) {
                return $zone;
            }
        }
        return null;
    }

    /**
     * Clear all recorded zones.
     */
    public function clear(): void
    {
        $this->zones = [];
    }
}
