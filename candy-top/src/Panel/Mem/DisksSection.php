<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/**
 * The P-D seam right of the mem|disks divider: btop Mem::draw's "Disks"
 * half (btop_draw.cpp:1390-1481 — mount rows, used/free meters, io
 * graphs, io_mode). Phase P-B leaves it empty; P-D implements this
 * interface and installs it with
 * {@see \SugarCraft\Top\Panel\MemPanel::withDisks()}.
 *
 * MemPanel already owns what btop's mem box owns for disks: the `d`
 * (show_disks) and `i` (io_mode) keys and their border-button clicks, and
 * the `io` button. The section only samples and paints:
 *  - {@see source()} is asked inside MemPanel::collect() with the CURRENT
 *    context (read show_disks / disks_filter / disks_order there); null
 *    skips sampling. It is sampled in the same Cmd as Memory.
 *  - {@see withSample()} folds that sample in (io history etc.) and keeps
 *    the next source.
 *  - {@see paint()} gets the whole mem box region plus `$area`, the local
 *    rectangle from the divider column (inclusive — btop's disk dividers
 *    start with `Mv::l(1)` onto it) to the right border, full box height.
 *    Only called while show_disks is on.
 */
interface DisksSection
{
    public function source(PanelContext $context): ?Source;

    public function withSample(object $snapshot, Source $next, PanelContext $context): self;

    public function paint(Region $box, PanelFrame $frame, Rect $area): void;
}
