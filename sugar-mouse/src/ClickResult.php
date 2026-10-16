<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * The result of a completed click: the zone that was clicked and the
 * button that was used.
 */
final readonly class ClickResult
{
    public function __construct(
        public Zone $zone,
        public int $button,
    ) {}
}
