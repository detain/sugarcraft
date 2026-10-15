<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Zone\Zone;

/**
 * Emitted when a mouse button is pressed inside a zone, starting a drag
 * sequence.
 *
 * Mirrors bubblezone's zone drag-start event.
 */
final class ZoneDragStartMsg implements Msg
{
    /**
     * @param Zone $originZone The zone the drag started from.
     * @param Zone $currentZone The zone the mouse is in when drag starts
     *                          (same as originZone at start; diverges on
     *                          subsequent moves if the cursor crosses zones).
     */
    public function __construct(
        public readonly Zone $originZone,
        public readonly Zone $currentZone,
    ) {}
}
