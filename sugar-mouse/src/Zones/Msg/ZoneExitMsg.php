<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Mouse\Zones\Zone;

/**
 * Emitted when the cursor exits a zone.
 *
 * Extends bubblezone — upstream ships no hover machinery and no such
 * event. The {@see Zone} object is
 * carried so handlers can inspect the zone's bounds without an
 * additional manager lookup.
 */
final class ZoneExitMsg implements Msg
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Zone $zone): self
    {
        return new self($zone);
    }


    public function __construct(
        public readonly Zone $zone,
    ) {}
}
