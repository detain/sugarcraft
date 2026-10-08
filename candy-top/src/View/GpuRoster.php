<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Top\Lang;

/**
 * What the layout needs to know about the detected accelerators — btop's
 * `Gpu::count`, `Gpu::gpu_b_height_offsets` and `gpu_names` — indexed the
 * way `gpuN` box names are: GPUs first, then NPUs (btop #985/#1839 append
 * the NPU to `gpus[]`, so `gpuK` past the last GPU is the NPU).
 *
 * An index the roster does not know (nothing sampled yet) sizes like
 * btop's `Gpu::min_height` box ({@see DEFAULT_OFFSET}) and has no name.
 */
final class GpuRoster
{
    /** btop Gpu::min_height (8) minus the 4 chrome rows. */
    public const DEFAULT_OFFSET = 4;

    /**
     * @param list<int>    $offsets stat rows per accelerator (btop gpu_b_height_offsets)
     * @param list<string> $names   model names per accelerator
     * @param int          $gpus    how many leading entries are GPUs (the rest are NPUs)
     */
    public function __construct(
        public readonly array $offsets = [],
        public readonly array $names = [],
        public readonly int $gpus = 0,
    ) {
    }

    /** Nothing detected (or not sampled yet). */
    public static function none(): self
    {
        return new self();
    }

    /** Accelerators known — btop Gpu::count (GPUs + NPUs). */
    public function count(): int
    {
        return \count($this->offsets);
    }

    /** GPUs only — what the cpu box's GPU rows count. */
    public function gpuCount(): int
    {
        return min($this->gpus, $this->count());
    }

    public function offset(int $index): int
    {
        return $this->offsets[$index] ?? self::DEFAULT_OFFSET;
    }

    public function name(int $index): string
    {
        return $this->names[$index] ?? '';
    }

    /** Index `$index` is an NPU (box label "npu", memory "ram"). */
    public function isNpu(int $index): bool
    {
        return $index >= $this->gpus && $index < $this->count();
    }

    /** The box/selector label of `$index`: "gpu<i>" or "npu<j>" (j counted among NPUs). */
    public function label(int $index): string
    {
        return $this->isNpu($index)
            ? Lang::t('box.npu') . ($index - $this->gpus)
            : Lang::t('box.gpu') . $index;
    }

    public function equals(self $other): bool
    {
        return $this->offsets === $other->offsets && $this->names === $other->names && $this->gpus === $other->gpus;
    }
}
