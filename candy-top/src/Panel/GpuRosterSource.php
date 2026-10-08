<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Top\View\GpuRoster;

/**
 * A panel that knows the detected accelerators (btop Gpu::count,
 * gpu_b_height_offsets, gpu_names). The App lays out the gpu box grid and
 * the cpu box's GPU rows from the largest roster any panel reports, and
 * re-runs the layout when a sample changes it.
 */
interface GpuRosterSource
{
    public function gpuRoster(): GpuRoster;
}
