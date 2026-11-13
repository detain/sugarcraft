<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Mouse\Zones\Zone;

/**
 * Emitted when a mouse button is pressed inside a zone, starting a drag
 * sequence.
 *
 * Extends bubblezone — upstream ships no drag machinery and no such
 * event.
 */
final class ZoneDragStartMsg implements Msg
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Zone $originZone, Zone $currentZone): self
    {
        return new self($originZone, $currentZone);
    }


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
