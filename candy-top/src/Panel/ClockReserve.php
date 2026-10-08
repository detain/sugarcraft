<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

/**
 * Optional {@see Panel} capability: the panel embeds something on the
 * border row the App's clock shares, and needs the clock to shrink.
 *
 * btop's update_clock budget is `max(10, width - 66 - (battery ? 22 : 0))`
 * (btop_draw.cpp:382), where `battery` is `show_battery and has_battery`
 * — panel state the App cannot know. Each view() the App asks every
 * visible panel implementing this interface (fresh {@see PanelContext});
 * if any says yes, the clock budget takes btop's 22-cell reserve (only on
 * terminals at least 100 columns wide, as in btop). The cpu panel answers
 * for its battery badge.
 */
interface ClockReserve
{
    /** True while this panel's border embed needs btop's clock reserve. */
    public function reservesClock(PanelContext $context): bool;
}
