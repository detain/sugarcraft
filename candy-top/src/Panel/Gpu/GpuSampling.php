<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Source\Source;

/**
 * Collect-time retuning of a GPU source for what its consumers need — the
 * shared feed ({@see GpuFeed}) tunes it for the union of every consumer's
 * {@see GpuDemand}, the startup probe for the config alone:
 *  - `shown_gpus` → {@see Accelerators::withVendors()} (btop's vendor
 *    filter; NPUs are never filtered);
 *  - per-process collection (#1552) → {@see Accelerators::withProcesses()}
 *    only while {@see processesWanted()} — the hook the proc box's GPU
 *    columns decide. It flips the collector only on a CHANGE: re-calling
 *    withProcesses(true) would clear a nvidia-smi pmon "unsupported" mark
 *    every tick.
 * {@see \SugarCraft\Top\Source\Fake\FakeGpu} takes the per-process
 * switch too; any other source (a scripted test source) is returned
 * unchanged.
 */
final class GpuSampling
{
    /**
     * The config key the proc box's #1552 GPU columns will be keyed on.
     * Until the Schema defines it, {@see processesWanted()} is false.
     */
    public const PROCESS_KEY = 'proc_gpu_columns';

    private function __construct()
    {
    }

    /**
     * The hook: whether GPU samples should carry per-process rows — the
     * proc box is shown and its GPU columns are on. A bool Schema option
     * named {@see PROCESS_KEY} turns it on; while no such option exists
     * this is false and no extra nvidia-smi / fdinfo work runs.
     */
    public static function processesWanted(Config $config): bool
    {
        $option = Schema::option(self::PROCESS_KEY);

        return $option !== null && \is_bool($option->default)
            && \in_array('proc', $config->shownBoxes(), true)
            && $config->bool(self::PROCESS_KEY);
    }

    /**
     * btop shown_gpus as vendor tokens ("nvidia amd intel apple"; unknown
     * words are ignored by the collector).
     *
     * @return list<string>
     */
    public static function vendors(Config $config): array
    {
        return preg_split('/\s+/', trim($config->string('shown_gpus')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** `$source` retuned for `$config` (vendors, per-process collection). */
    public static function tune(?Source $source, Config $config): ?Source
    {
        return $source === null ? null : self::tuneFor($source, GpuDemand::of($config));
    }

    /** `$source` retuned for `$demand`: vendors, and per-process collection flipped only on a change. */
    public static function tuneFor(Source $source, GpuDemand $demand): Source
    {
        if ($source instanceof CollectorSource && ($collector = $source->collector()) instanceof Accelerators) {
            $source = CollectorSource::of($collector->withVendors($demand->vendors));
        }

        return self::withProcesses($source, $demand->processes);
    }

    /**
     * `$source` with per-process collection (btop #1552) on or off — the
     * live collectors and FakeGpu; flipped only on a CHANGE, since a
     * re-opt-in clears pmon's "unsupported" mark and the spawns' backoffs.
     */
    public static function withProcesses(Source $source, bool $on): Source
    {
        if ($source instanceof FakeGpu) {
            return $source->processesEnabled() === $on ? $source : $source->withProcesses($on);
        }
        if (!$source instanceof CollectorSource) {
            return $source;
        }
        $collector = $source->collector();
        if (!($collector instanceof Accelerators || $collector instanceof Gpu) || $collector->processesEnabled() === $on) {
            return $source;
        }

        return CollectorSource::of($collector->withProcesses($on));
    }
}
