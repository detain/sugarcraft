<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Zone\Zone;

/**
 * Emitted on every mouse-move event while a drag is in progress.
 *
 * The origin zone never changes for the lifetime of a drag; the current
 * zone updates whenever the cursor crosses a zone boundary.
 *
 * Mirrors bubblezone's zone drag-move event.
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
