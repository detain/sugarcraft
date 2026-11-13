<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Dock;

use SugarCraft\Constraints\Region;

/**
 * Immutable result of {@see DockLayout::resolve()} — concrete grid regions
 * for one frame.
 *
 * Immutable in full, precisely: `final readonly` freezes the two array props
 * against reassignment AND element writes through them (PHP 8.3 answers both
 * with "Cannot modify readonly property"), and {@see Region} is itself
 * readonly — so nothing reachable from a geometry can be mutated. Hosts that
 * want a scratch copy get one for free: every read of `$regions` hands the
 * array out by value, so writes land on the copy only.
 *
 * Original SugarCraft design (no upstream mirror).
 *
 * The center pane is carried INSIDE {@see $regions} keyed by its pane id
 * (design choice: one lookup path for hosts, no special-casing) rather than a
 * separate nullable field. Iteration order of $regions is insertion order:
 * left-stack slots (top to bottom), center, right-stack slots — do not rely
 * on it for pane identity; key by pane id. A degenerate frame yields a
 * geometry with no regions at all ({@see isEmpty()}) — never an exception; the
 * host decides its fallback.
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
    }

    /**
     * The validating public door for untrusted payloads: every element of the
     * shape is checked before construction (the scan used to live in the
     * private constructor, where resolve() paid it once per frame on bytes it
     * had just built itself — finding 028 moved it to this door; the paint
     * path takes {@see solved()} instead).
     *
     * @param array<string,Region> $regions
     * @param list<array{x:int,side:Side}> $dividerColumns
     */
    public static function new(array $regions = [], array $dividerColumns = []): self
    {
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

        return new self($regions, $dividerColumns);
    }

    /**
     * @internal construction from {@see DockLayout::resolve()} — the walk
     * builds every Region and divider row itself, so re-scanning its own
     * output element-by-element each paint is pure overhead (finding 028,
     * once per frame on the paint path). Hosts keep using {@see new()},
     * which validates untrusted payloads. Same private constructor, zero
     * validation — that is the whole difference, and it is why this seam is
     * @internal.
     *
     * @param array<string,Region> $regions
     * @param list<array{x:int,side:Side}> $dividerColumns
     */
    public static function solved(array $regions, array $dividerColumns): self
    {
        return new self($regions, $dividerColumns);
    }

    /**
     * The region assigned to a pane, or null when the pane is absent from
     * this geometry. Absent covers three distinct states — the pane was never
     * docked, its side was dropped by the degradation ladder, or the frame was
     * degenerate. A null alone does not say which; hosts that must distinguish
     * a dropped side from an undocked pane compare against the input
     * {@see DockLayout} (its slots()/columnShare() still describe intent).
     * Note a 0-width region is NOT null: an active side planned at 0 columns
     * keeps a width-0 region by design (see the DockLayout class notes).
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
