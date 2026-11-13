<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Mouse\Zones\Zone;

/**
 * Emitted when the cursor crosses into a different zone during a drag —
 * motion inside the current zone emits nothing, and dragging off all zones
 * clears the current zone silently (there is no zone to name).
 *
 * The origin zone never changes for the lifetime of a drag; the current
 * zone updates whenever the cursor crosses a zone boundary.
 *
 * Extends bubblezone — upstream ships no drag machinery and no such
 * event.
 */
final class ZoneDragMoveMsg implements Msg
{
    /**
     * @param Zone $originZone The zone the drag started from (unchanging).
     * @param Zone $currentZone The zone the mouse cursor is currently in.
     */
    public function __construct(
        public readonly Zone $originZone,
        public readonly Zone $currentZone,
    ) {}
}
