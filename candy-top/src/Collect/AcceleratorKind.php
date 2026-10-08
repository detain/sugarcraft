<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * What an accelerator device is: a GPU, or an NPU (btop #985 Intel NPU,
 * #1839 AMD NPU). Both PRs append the NPU to btop's `gpus[]` and flip the
 * box labels (`is_npu_device` / `box_label`); here the kind travels on the
 * device and {@see GpuSnapshot} keeps NPUs in their own list so GPU
 * averages and totals never include them (#985 review).
 */
enum AcceleratorKind: string
{
    case Gpu = 'gpu';
    case Npu = 'npu';

    /** The box/row label btop prints: "GPU" or "NPU". */
    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** The memory label: btop shows "VRAM" for a GPU, #985 "RAM" for an NPU. */
    public function memoryLabel(): string
    {
        return $this === self::Gpu ? 'VRAM' : 'RAM';
    }
}
