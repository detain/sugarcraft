<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

use SugarCraft\Core\Msg;

/**
 * Sent (through the `Cmd` that {@see Tabs::update()}, {@see Tabs::clickAt()}
 * or {@see Tabs::clickZone()} returns) after a closable bar closed a tab,
 * either from a click on the tab's close affordance or from the close key.
 *
 * The bar has already dropped the tab when this arrives. `index` and
 * `label` describe the tab as it was before removal, so a host that keeps
 * parallel per-tab state (sessions, documents) removes the same slot.
 * `zoneKey` names the bar, so a host with several bars can tell them apart.
 */
final class TabClosedMsg implements Msg
{
    /** House `::new()` factory, same signature as the constructor. */
    public static function new(int $index, string $label, string $zoneKey): self
    {
        return new self($index, $label, $zoneKey);
    }

    public function __construct(
        public readonly int $index,
        public readonly string $label,
        public readonly string $zoneKey,
    ) {}
}
