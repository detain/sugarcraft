<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Stateful scan-then-get fluent API for mouse hit-testing.
 *
 * Mutable layer — see README §Choosing your tracker idiom.
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
     * Lazily-built spatial index over {@see $zones} (the package's one hit
     * rule lives there). null until the first {@see hit()} after a
     * {@see scan()}/{@see clear()}.
     *
     * @var ZoneGrid<Zone>|null
     */
    private ?ZoneGrid $grid = null;

    /** Widths of unchanged inert lines, carried from one scan to the next. */
    private readonly LineWidthCache $lineWidths;

    public function __construct()
    {
        $this->lineWidths = LineWidthCache::new();
    }

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
     * Lines that carry no zone markup and did not change since the previous
     * scan are not re-measured: their widths come from this scanner's
     * {@see LineWidthCache} ({@see lineWidths()}).
     *
     * @param string $rendered The rendered string containing zone sentinels.
     * @param int|null $width  Optional terminal viewport width.  When set,
     *                         zone start/end columns are clamped into
     *                         [1, width].
     */
    public function scan(string $rendered, ?int $width = null): self
    {
        $this->zones = Scan::parse($rendered, $width, $this->lineWidths);
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
     * The SELECTING side of the prefix vocabulary: `Manager::newPrefix()`
     * stamps namespaces onto ids, this filter reads them back out.
     *
     * @return array<string, Zone>
     */
    public function idsStartingWith(string $prefix): array
    {
        $result = [];
        foreach ($this->zones as $id => $zone) {
            if (str_starts_with((string) $id, $prefix) === true) { // numeric ids are int keys
                $result[$id] = $zone;
            }
        }
        return $result;
    }

    /**
     * @deprecated Renamed to {@see idsStartingWith()} — one word, one
     *             direction: stamps belong to `newPrefix()`, this filter
     *             selects. The alias delegates byte-for-byte; removal is a
     *             gated BC decision, not an accident.
     *
     * @return array<string, Zone>
     */
    public function prefixed(string $prefix): array
    {
        return $this->idsStartingWith($prefix);
    }

    /**
     * Reverse-lookup: the zone that owns cell (col, row), or null if no zone
     * covers it.
     *
     * THE hit rule, shared with {@see \SugarCraft\Mouse\Zones\Manager::zoneAt()}
     * and `anyInBounds()` through {@see ZoneGrid}: the SMALLEST covering area
     * wins (nested components: the innermost one), and equal areas go to the
     * zone registered first (scan close order). Both layers therefore name
     * the same owner for a painted frame.
     *
     * Backed by a lazily-built grid-bucket index, so a lookup scans only the
     * candidates of one bucket instead of every zone. The index is built once
     * on the first call after a scan and reused until the zone set changes.
     *
     * @param int $col Terminal column (1-based).
     * @param int $row Terminal row (1-based).
     */
    public function hit(int $col, int $row): ?Zone
    {
        if ($this->zones === []) {
            return null;
        }
        $this->grid ??= ZoneGrid::of($this->zones);
        return $this->grid->smallestAt($col, $row);
    }

    /**
     * Every zone covering cell (col, row), innermost first (ascending area,
     * equal areas in registration order) — the full stack {@see hit()} picks
     * the head of.
     *
     * @return list<Zone>
     */
    public function hitAll(int $col, int $row): array
    {
        if ($this->zones === []) {
            return [];
        }
        $this->grid ??= ZoneGrid::of($this->zones);
        return $this->grid->allAt($col, $row);
    }

    /**
     * The zone recorded under a typed id — the {@see ZoneId} spelling of
     * {@see get()}.
     */
    public function zone(ZoneId $id): ?Zone
    {
        return $this->zones[$id->wire()] ?? null;
    }

    /** The line-width cache {@see scan()} reads and fills. */
    public function lineWidths(): LineWidthCache
    {
        return $this->lineWidths;
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
