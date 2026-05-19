<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Zone\Zone;

/**
 * Emitted when the cursor exits a zone.
 *
 * Mirrors bubblezone's zone exit event. The {@see Zone} object is
 * carried so handlers can inspect the zone's bounds without an
 * additional manager lookup.
 */
final class ZoneExitMsg implements Msg
{
    public function __construct(
        public readonly Zone $zone,
    ) {}
}
