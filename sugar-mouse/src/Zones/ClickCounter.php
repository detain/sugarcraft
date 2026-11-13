<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

/*
 * Back-compat alias for the renamed streak tracker.

 * `ClickCounter` mirrored upstream's `NewZoneClickTracker` naming while
 * this lib's own {@see ZoneClickTracker} is a different machine (single
 * Press+Release dedup, not streak counting) — the collision was a
 * reader trap, so the class now ships as {@see MultiClickTracker} and
 * this file only keeps the old symbol loadable.
 *
 * @deprecated Renamed to {@see MultiClickTracker}; this alias stays for
 *             one release cycle of the 2026-11 rename wave.
 */

class_alias(MultiClickTracker::class, 'SugarCraft\Mouse\Zones\ClickCounter');
