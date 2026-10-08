<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Top\Config\GpuPanels;

/**
 * One laid-out gpu box (btop calcSizes' per-panel `Gpu::x_vec`, `y_vec`,
 * `width_vec`, `b_x_vec`, `b_y_vec`, `b_width_vec`, `b_height_vec`), all
 * 0-based and absolute.
 *
 * `selector` holds the absolute columns of the title's `←` / `→` zones
 * (btop PR #1730 mouse_mappings gpu_prev_N / gpu_next_N, on the box's top
 * row), null when btop would not draw the selector (one accelerator, or
 * the box is too narrow for it). `label` is the selector text (`gpu1`,
 * or `npu0` for an NPU — btop #985 relabels NPU boxes), `deviceName` the
 * detected model name the stats sub-box is titled with.
 */
final class GpuBox
{
    /**
     * @param array{0: int, 1: int}|null $selector [prev x, next x]
     */
    public function __construct(
        public readonly string $name,
        public readonly int $index,
        public readonly int $panel,
        public readonly int $slot,
        public readonly Rect $rect,
        public readonly Rect $stats,
        public readonly GpuDetail $detail,
        public readonly int $graphHeight,
        public readonly ?array $selector = null,
        public readonly string $label = '',
        public readonly bool $npu = false,
        public readonly string $deviceName = '',
    ) {
    }

    /** The digit in the box title — the slot's toggle key (btop gpu_panel_key; 0 draws none). */
    public function key(): int
    {
        return GpuPanels::key($this->slot);
    }
}
