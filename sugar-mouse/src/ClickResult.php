<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * The result of a completed click: the zone that was clicked and the
 * button that was used.
 */
final readonly class ClickResult
{
    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Zone $zone, int $button): self
    {
        return new self($zone, $button);
    }


    public function __construct(
        public Zone $zone,
        public int $button,
    ) {}
}
