<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region as BufferRegion;
use SugarCraft\Layout\Region as LayoutRegion;

/**
 * Converts between the two deliberately-distinct `Region` value objects that
 * share a name across packages:
 *
 *  - {@see \SugarCraft\Layout\Region} — candy-layout's solver output (a leaf
 *    package that must stay dependency-free, hence its own Region type).
 *  - {@see \SugarCraft\Buffer\Region} — candy-buffer's buffer sub-region.
 *
 * The name collision is intentional (see the docblock on candy-layout's
 * Region). candy-sprinkles is the natural home for the bridge because it is
 * the lowest package that already depends on BOTH candy-layout and
 * candy-buffer, so neither leaf package has to take on the other as a dep.
 *
 * Field mapping is 1:1 and both classes are 0-based:
 *   Layout x      ↔ Buffer origin->col
 *   Layout y      ↔ Buffer origin->row
 *   Layout width  ↔ Buffer width
 *   Layout height ↔ Buffer height
 *
 * WHY this is not a no-op cast: candy-buffer's {@see Position} permits
 * negative coordinates (relative offsets), whereas candy-layout's Region
 * guards against negatives in its constructor. Converting a negative-origin
 * buffer Region via {@see toLayout()} therefore throws
 * \InvalidArgumentException from candy-layout's own guard — that is the
 * correct behaviour, not something the bridge should paper over.
 */
final class RegionBridge
{
    /** Static-only converter; never instantiated. */
    private function __construct()
    {
    }

    /**
     * candy-layout Region → candy-buffer Region.
     *
     * Always succeeds: layout Regions are non-negative, so the buffer Region
     * (which accepts any origin) can represent every layout Region.
     */
    public static function toBuffer(LayoutRegion $r): BufferRegion
    {
        return new BufferRegion(
            new Position($r->x, $r->y),
            $r->width,
            $r->height,
        );
    }

    /**
     * candy-buffer Region → candy-layout Region.
     *
     * @throws \InvalidArgumentException if the buffer Region has a negative
     *         origin or dimension — candy-layout's Region rejects those.
     */
    public static function toLayout(BufferRegion $r): LayoutRegion
    {
        return new LayoutRegion(
            $r->origin->col,
            $r->origin->row,
            $r->width,
            $r->height,
        );
    }
}
