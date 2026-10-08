<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Collect\GpuSnapshot;

/**
 * Per-pid GPU utilization and memory for the proc list (btop #1552) — the
 * join between the GPU collector's per-process rows and ProcList's pids.
 *
 * The GPU source runs on its own cadence: nvidia-smi is queried at most
 * every 5 s (samples in between repeat its last snapshot) and its
 * per-process spawns back off on their own, reporting `processes = null`
 * for a cycle they did not measure. So the values are HELD:
 *  - a snapshot with `processes === null` keeps every pid's last values;
 *  - a measured snapshot replaces the map — a pid it does not list reads
 *    0 / 0 (it stopped using the GPU or exited);
 *  - inside a measured snapshot an UNMEASURED utilization (compute-apps
 *    without pmon, a DRM client's first sight) or memory ([N/A] under
 *    WDDM) keeps that pid's previous value, else 0 (#1008).
 * Utilization is summed over the pid's GPUs ({@see GpuSnapshot::utilizationByPid()})
 * and clamped to 0-100, as the PR clamps gpu_p; memory is summed bytes.
 *
 * {@see measured()} is sticky: once any snapshot carried per-process rows
 * the host has per-process GPU data, which is what lets the proc box draw
 * its Gpu% / GMem columns. {@see cleared()} drops the values (the panel
 * stopped sampling the GPU) but keeps that flag. {@see fresh()} says the
 * values come from the GPU's latest sample: false after cleared() until
 * the next measured snapshot — while it is false, proc_gpu_only must not
 * judge (every pid would read 0 and the list would empty) and the panel
 * resamples at once when the filter or a gpu sort is turned on.
 *
 * Known flicker: on a host with an NVIDIA card AND an amdgpu/i915/xe card,
 * a cycle where nvidia-smi's compute-apps / pmon spawns are backing off
 * still yields a non-null `processes` list (the DRM fdinfo rows alone), so
 * that cycle reads the NVIDIA pids as 0 / "-" until the spawns answer
 * again. Telling "not measured" from "not listed" per backend would need
 * a per-vendor null in GpuSnapshot.
 */
final class GpuUsage
{
    /**
     * @param array<int, array{0: float, 1: int}> $byPid pid => [utilization %, memory bytes]
     */
    private function __construct(
        private readonly array $byPid,
        private readonly bool $measured,
        private readonly bool $fresh = false,
    ) {
    }

    public static function none(): self
    {
        return new self([], false);
    }

    /** Fold one GPU snapshot in (hold rules above). */
    public function withSnapshot(GpuSnapshot $snapshot): self
    {
        if ($snapshot->processes === null) {
            return $this;
        }
        $mem = $snapshot->memoryByPid();
        $out = [];
        foreach ($snapshot->utilizationByPid() as $pid => $util) {
            $prev = $this->byPid[$pid] ?? [0.0, 0];
            $m = $mem[$pid] ?? -1;
            $out[$pid] = [
                $util >= 0.0 ? max(0.0, min(100.0, $util)) : $prev[0],
                $m >= 0 ? $m : $prev[1],
            ];
        }

        return new self($out, true, true);
    }

    /** The values dropped, the measured flag kept, no longer fresh. */
    public function cleared(): self
    {
        return $this->byPid === [] && !$this->fresh ? $this : new self([], $this->measured, false);
    }

    /** Whether the values come from the latest measured GPU sample (not cleared since). */
    public function fresh(): bool
    {
        return $this->fresh;
    }

    /** Whether per-process GPU data was ever measured (columns / filter available). */
    public function measured(): bool
    {
        return $this->measured;
    }

    /** GPU utilization percent of `$pid`, 0 when not listed. */
    public function utilization(int $pid): float
    {
        return $this->byPid[$pid][0] ?? 0.0;
    }

    /** GPU memory bytes of `$pid`, 0 when not listed. */
    public function memory(int $pid): int
    {
        return $this->byPid[$pid][1] ?? 0;
    }

    /** @return list<int> the pids with a value */
    public function pids(): array
    {
        return array_keys($this->byPid);
    }
}
