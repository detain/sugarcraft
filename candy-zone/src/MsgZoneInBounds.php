<?php

declare(strict_types=1);

namespace CandyCore\Zone;

use CandyCore\Core\Msg;
use CandyCore\Core\Msg\MouseMsg;

/**
 * Mouse-event Msg paired with the zone the click landed inside.
 *
 * Mirrors bubblezone's `MsgZoneInBounds`. Emitted by
 * {@see Manager::anyInBounds()} / {@see Manager::anyInBoundsAndUpdate()}
 * when a {@see MouseMsg} is dispatched and at least one tracked zone
 * contains the click. Models route this Msg directly to the matching
 * sub-component without doing the per-zone hit-test themselves.
 */
final class MsgZoneInBounds implements Msg
{
    public function __construct(
        public readonly Zone $zone,
        public readonly MouseMsg $mouse,
    ) {}
}
