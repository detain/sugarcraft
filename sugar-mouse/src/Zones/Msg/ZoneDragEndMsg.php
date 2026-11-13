<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Mouse\Zones\Zone;

/**
 * Emitted when the drag button is released, ending a drag sequence.
 *
 * Extends bubblezone — upstream ships no drag machinery and no such
 * event.
 */
final class ZoneDragEndMsg implements Msg
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Zone $originZone, ?Zone $currentZone): self
    {
        return new self($originZone, $currentZone);
    }


    /**
     * @param Zone $originZone The zone the drag started from.
     * @param ?Zone $currentZone The zone the mouse cursor was in at the
     *                           release itself (may differ from originZone
     *                           if the cursor crossed zones during the drag;
     *                           null when the release happened outside every
     *                           tracked zone).
     */
    public function __construct(
        public readonly Zone $originZone,
        public readonly ?Zone $currentZone,
    ) {}
}
