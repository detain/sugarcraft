<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Source;

/**
 * Collect-time retuning of a GPU source from the CURRENT config, shared by
 * the cpu box (show_gpu_info) and the gpu boxes:
 *  - `shown_gpus` → {@see Accelerators::withVendors()} (btop's vendor
 *    filter; NPUs are never filtered);
 *  - per-process collection (#1552) → {@see Accelerators::withProcesses()}
 *    only while {@see processesWanted()} — the hook the proc box's GPU
 *    columns decide. It flips the collector only on a CHANGE: re-calling
 *    withProcesses(true) would clear a nvidia-smi pmon "unsupported" mark
 *    every tick.
 * Any other source (a fake, a scripted test source) is returned unchanged.
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
        if (!$source instanceof CollectorSource) {
            return $source;
        }
        $collector = $source->collector();
        if (!$collector instanceof Accelerators) {
            return $source;
        }
        $collector = $collector->withVendors(self::vendors($config));
        $want = self::processesWanted($config);
        if ($collector->processesEnabled() !== $want) {
            $collector = $collector->withProcesses($want);
        }

        return CollectorSource::of($collector);
    }
}
