<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;

/**
 * One vendor's accelerator collector — btop's per-library namespaces
 * (Gpu::Nvml, Gpu::Rsmi, Gpu::Asysfs, Gpu::Intel in
 * src/linux/btop_collect.cpp), each filling its own slice of `gpus[]`.
 * {@see Accelerators} polls every present backend and merges the slices
 * in btop's order (NVIDIA, AMD, Intel; NPUs in their own list).
 *
 * A backend's devices are indexed from 0 within the backend; the merge
 * re-indexes them. Every backend is immutable (`poll()` returns the next
 * state) and total: a missing node is a sentinel, never an exception.
 */
interface Backend
{
    /**
     * @param DrmScan $scan this cycle's DRM fdinfo scan ({@see DrmScan::none()} when
     *                      no backend asked for one)
     * @return array{0: GpuSnapshot, 1: Backend}
     */
    public function poll(DrmScan $scan): array;

    public function vendor(): GpuVendor;

    public function kind(): AcceleratorKind;

    /** Its utilization comes from DRM fdinfo, so a scan must run every cycle (Intel, #1888). */
    public function needsDrmScan(): bool;

    /**
     * Its devices publish DRM fdinfo clients (amdgpu, i915, xe), so the
     * per-process scan (#1552) can attribute processes to them. False for
     * NVIDIA's proprietary driver — the #1552 reviewer's guard: a scan
     * that can never match costs ~10 ms per tick for nothing.
     */
    public function drmClients(): bool;
}
