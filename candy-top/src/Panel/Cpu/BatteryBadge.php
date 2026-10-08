<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Cpu;

use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/**
 * The P-D seam in the cpu box border: btop's `BAT▲ 87% ■■■■ 1:23 12.5W`
 * badge (btop_draw.cpp:766-806, Cpu::draw "Draw battery if enabled and
 * present"). Phase P-B leaves it empty; P-D implements this interface and
 * installs it with {@see \SugarCraft\Top\Panel\CpuPanel::withBattery()}.
 *
 * Lifecycle, driven by CpuPanel so the App needs no change:
 *  - {@see source()} is asked inside CpuPanel::collect() with the CURRENT
 *    context; return null to skip sampling (e.g. show_battery off). The
 *    returned Source is sampled in the same Cmd as the cpu collectors.
 *  - {@see withSample()} receives that sample's snapshot and the next
 *    source inside CpuPanel::update(); keep the source for the next tick.
 *  - {@see paint()} gets the WHOLE cpu box region (border rows included)
 *    after the graphs and core grid are painted; draw on row 0, or the
 *    bottom row when `$frame->layout->cpuBottom`. btop positions the badge
 *    at `Term::width - len - 17` (1-based), right of the clock budget.
 */
interface BatteryBadge
{
    public function source(PanelContext $context): ?Source;

    public function withSample(object $snapshot, Source $next, PanelContext $context): self;

    public function paint(Region $box, PanelFrame $frame): void;
}
