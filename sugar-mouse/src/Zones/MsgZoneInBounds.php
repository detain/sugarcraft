<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;

/**
 * Mouse-event Msg paired with the zone the click landed inside.
 *
 * Mirrors bubblezone's `MsgZoneInBounds`. Emitted solely by
 * {@see Manager::anyInBoundsAndUpdate()} — the only path that hands a Msg
 * back to a Model — when at least one tracked zone contains the click.
 * {@see Manager::anyInBounds()} merely RETURNS the zone; it dispatches
 * nothing. Models route this Msg directly to the matching
 * sub-component without doing the per-zone hit-test themselves.
 */
final class MsgZoneInBounds implements Msg
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Zone $zone, MouseMsg $mouse): self
    {
        return new self($zone, $mouse);
    }


    public function __construct(
        public readonly Zone $zone,
        public readonly MouseMsg $mouse,
    ) {}
}
