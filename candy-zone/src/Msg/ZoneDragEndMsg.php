<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Zone\Zone;

/**
 * Emitted when the mouse button is released, ending a drag sequence.
 *
 * Mirrors bubblezone's zone drag-end event.
 */
final class ZoneDragEndMsg implements Msg
{
    /**
     * @param Zone $originZone The zone the drag started from.
     * @param Zone $currentZone The zone the mouse cursor was in at
     *                          release (may differ from originZone if
     *                          the cursor crossed zones during the drag).
     */
    public function __construct(
        public readonly Zone $originZone,
        public readonly Zone $currentZone,
    ) {}
}
