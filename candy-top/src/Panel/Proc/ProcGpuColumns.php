<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Source;

/**
 * When the proc box needs per-process GPU data (btop #1552), and how its
 * GPU source is tuned for it.
 *
 * {@see wanted()} is the collect-time gate, the #1823 io rule's analog:
 * the GPU source is sampled only while a Gpu% column can be on screen
 * (the box is wide enough, {@see ProcView::sizes()}), proc_gpu_only is on,
 * or a gpu sort is active — otherwise the nvidia-smi compute-apps / pmon
 * spawns and the DRM fdinfo walk are not paid at all. Without a width
 * (wiring time, or a hidden box) it answers whether the config alone could
 * want it, which is true whenever the box may be wide.
 *
 * {@see tuned()} switches per-process collection on for the collector
 * (`withProcesses()`, off by default because it costs extra spawns); a
 * fake or other source passes through unchanged.
 */
final class ProcGpuColumns
{
    /** btop PR #1552's sort keys. */
    public const SORTS = ['gpu', 'gpu memory'];

    private function __construct()
    {
    }

    /**
     * Whether the proc box wants per-process GPU data under `$config` in a
     * box `$width` cells wide (null = unknown: assume it may be wide).
     */
    public static function wanted(Config $config, ?int $width = null): bool
    {
        if ($config->bool('proc_gpu_only') || in_array($config->procSorting(), self::SORTS, true)) {
            return true;
        }
        if ($width === null) {
            return true;
        }

        return ProcView::sizes($width, $config->bool('proc_cpu_graphs'), true, $config->bool('proc_gpu_graphs'))['gpu'] > 0;
    }

    /** `$source` with per-process collection switched on (collectors only). */
    public static function tuned(Source $source): Source
    {
        if (!$source instanceof CollectorSource) {
            return $source;
        }
        $c = $source->collector();
        if (($c instanceof Accelerators || $c instanceof Gpu) && !$c->processesEnabled()) {
            return CollectorSource::of($c->withProcesses(true));
        }

        return $source;
    }
}
