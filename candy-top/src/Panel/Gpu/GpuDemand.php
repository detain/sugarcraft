<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Top\Config\Config;

/**
 * What one consumer of the shared GPU feed ({@see GpuFeed}) needs from a
 * sample: the GPU vendors it shows (btop `shown_gpus` tokens) and whether
 * it needs per-process rows (btop #1552). The feed samples the UNION of
 * every live consumer's demand, so the nvidia-smi compute-apps / pmon
 * spawns and the DRM fdinfo walk run only while some consumer asks for
 * per-process data.
 */
final class GpuDemand
{
    /**
     * @param list<string> $vendors shown_gpus tokens (unknown words are ignored by the collector)
     */
    private function __construct(
        public readonly array $vendors,
        public readonly bool $processes,
    ) {
    }

    /**
     * The demand `$config` implies: its shown_gpus, and per-process rows
     * when `$processes` (the proc box's columns) or the config-level hook
     * {@see GpuSampling::processesWanted()} asks for them.
     */
    public static function of(Config $config, bool $processes = false): self
    {
        return new self(GpuSampling::vendors($config), $processes || GpuSampling::processesWanted($config));
    }

    /**
     * @param list<string> $vendors
     */
    public static function new(array $vendors = ['nvidia', 'amd', 'intel'], bool $processes = false): self
    {
        return new self(array_values(array_unique($vendors)), $processes);
    }

    public function withProcesses(bool $on = true): self
    {
        return new self($this->vendors, $on);
    }

    /** Every vendor either names; per-process rows when either wants them. */
    public function union(self $other): self
    {
        return new self(array_values(array_unique([...$this->vendors, ...$other->vendors])), $this->processes || $other->processes);
    }
}
