<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;

/**
 * Everything a {@see Panel} may read while painting one frame: the full
 * {@see Layout} (sub-box rectangles such as cpuCores / netStats /
 * memDivider), its own box rectangle, the resolved theme, the border
 * family, config and host facts. Rebuilt by the App per view().
 */
final class PanelFrame
{
    public function __construct(
        public readonly Layout $layout,
        public readonly Rect $box,
        public readonly Ink $ink,
        public readonly Border $border,
        public readonly Config $config,
        public readonly HostInfo $host,
    ) {
    }

    /** `$absolute` (a Layout rectangle) in this box's local coordinates. */
    public function local(Rect $absolute): Rect
    {
        return $absolute->translate(-$this->box->x, -$this->box->y);
    }

    public function tty(): bool
    {
        return $this->config->ttyMode();
    }
}
