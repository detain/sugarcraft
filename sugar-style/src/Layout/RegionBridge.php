<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region as BufferRegion;
use SugarCraft\Constraints\Region as ConstraintsRegion;

/**
 * Converts between the two deliberately-distinct `Region` value objects that
 * share a name across packages:
 *
 *  - {@see \SugarCraft\Constraints\Region} — sugar-constraints's solver output (a leaf
 *    package that must stay dependency-free, hence its own Region type).
 *  - {@see \SugarCraft\CellBuffer\Region} — sugar-cellbuffer's buffer sub-region.
 *
 * The name collision is intentional (see the docblock on sugar-constraints's
 * Region). sugar-style is the natural home for the bridge because it is
 * the lowest package that already depends on BOTH sugar-constraints and
 * sugar-cellbuffer, so neither leaf package has to take on the other as a dep.
 *
 * Field mapping is 1:1 and both classes are 0-based:
 *   Constraints x ↔ Buffer origin->col
 *   Constraints y ↔ Buffer origin->row
 *   Constraints width ↔ Buffer width
 *   Constraints height ↔ Buffer height
 *
 * WHY this is not a no-op cast: sugar-cellbuffer's {@see Position} permits
 * negative coordinates (relative offsets), whereas sugar-constraints's Region
 * guards against negatives in its constructor. Converting a negative-origin
 * buffer Region via {@see toConstraints()} therefore throws
 * \InvalidArgumentException from sugar-constraints's own guard — that is the
 * correct behaviour, not something the bridge should paper over.
 */
final class RegionBridge
{
    /** Static-only converter; never instantiated. */
    private function __construct()
    {
    }

    /**
     * sugar-constraints Region → sugar-cellbuffer Region.
     *
     * Always succeeds: layout Regions are non-negative, so the buffer Region
     * (which accepts any origin) can represent every layout Region.
     */
    public static function toBuffer(ConstraintsRegion $r): BufferRegion
    {
        return new BufferRegion(
            new Position($r->x, $r->y),
            $r->width,
            $r->height,
        );
    }

    /**
     * sugar-cellbuffer Region → sugar-constraints Region.
     *
     * @throws \InvalidArgumentException if the buffer Region has a negative
     *         origin or dimension — sugar-constraints's Region rejects those.
     */
    public static function toConstraints(BufferRegion $r): ConstraintsRegion
    {
        return new ConstraintsRegion(
            $r->origin->col,
            $r->origin->row,
            $r->width,
            $r->height,
        );
    }
}
