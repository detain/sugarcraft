<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Dock;

use SugarCraft\Layout\Region;

/**
 * Immutable result of {@see DockLayout::resolve()} — concrete grid regions
 * for one frame.
 *
 * Original SugarCraft design (no upstream mirror).
 *
 * The center pane is carried INSIDE {@see $regions} keyed by its pane id
 * (design choice: one lookup path for hosts, no special-casing) rather than a
 * separate nullable field. A degenerate frame yields a geometry with no
 * regions at all ({@see isEmpty()}) — never an exception; the host decides
 * its fallback.
 */
final readonly class DockGeometry
{
    /**
     * @param array<string,Region> $regions paneId => Region, center included under its pane id
     * @param list<array{x:int,side:Side}> $dividerColumns one row per active side's divider column
     */
    private function __construct(
        public array $regions,
        public array $dividerColumns,
    ) {
        foreach ($regions as $paneId => $region) {
            if ($region instanceof Region === false) {
                throw new \InvalidArgumentException(
                    "DockGeometry region '{$paneId}' must be a Region"
                );
            }
        }
        foreach ($dividerColumns as $column) {
            if (isset($column['x'], $column['side']) === false
                || is_int($column['x']) === false
                || $column['side'] instanceof Side === false
            ) {
                throw new \InvalidArgumentException(
                    'DockGeometry divider columns must be [x => int, side => Side] rows'
                );
            }
        }
    }

    /**
     * @param array<string,Region> $regions
     * @param list<array{x:int,side:Side}> $dividerColumns
     */
    public static function new(array $regions = [], array $dividerColumns = []): self
    {
        return new self($regions, $dividerColumns);
    }

    /**
     * The region assigned to a pane, or null when the pane is absent from
     * this geometry (dropped side, or degenerate frame).
     */
    public function regionFor(string $paneId): ?Region
    {
        return $this->regions[$paneId] ?? null;
    }

    /**
     * @return list<array{x:int,side:Side}>
     */
    public function dividerColumns(): array
    {
        return $this->dividerColumns;
    }

    /**
     * True when the frame was degenerate (non-positive width/height) and the
     * layout resolved to nothing.
     */
    public function isEmpty(): bool
    {
        return $this->regions === [];
    }
}
