<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Aggregate and per-core CPU utilisation from kern.cp_times tick deltas.
 *
 * Mirrors aristocratos/btop Cpu::collect (src/freebsd/btop_collect.cpp):
 *  - kern.cp_times is CPUSTATES (5) longs per core, in core order:
 *    user, nice, sys, intr, idle;
 *  - the aggregate is the sum over cores (btop sums the per-core rows
 *    rather than reading kern.cp_time, so the two can never disagree);
 *  - load averages from vm.loadavg (btop getloadavg), uptime = now −
 *    kern.boottime (btop system_uptime).
 *
 * Deviation: btop sums only user+nice+sys+idle and drops CP_INTR from the
 * denominator, so interrupt time vanishes from the graph entirely. Here
 * intr stays in totals and is reported as the `irq` field, as top(1)
 * does — an interrupt storm reads as busy, not idle. Like the Linux
 * collector, the first sample is the since-boot average and a zero
 * interval reads UNMEASURED instead of btop's max(1, Δ) spike.
 *
 * kern.cp_times is sized by mp_maxid + 1, which can exceed the cpus
 * actually present; rows past hw.ncpu × 5 are dropped (btop sizes its
 * buffer by Shared::coreCount the same way).
 *
 * Cost: one `sysctl` child per sample. hw.ncpu is static, so it is read
 * with the first successful sample and carried forward; kern.boottime is
 * re-read every sample (same child, no extra cost) because a clock step
 * moves it — a cached value would skew uptime by the step forever.
 * Immutable: sample() returns the snapshot and the collector carrying the
 * new baseline.
 */
final class Cpu
{
    /** CPUSTATES order (sys/resource.h) → the Linux collector's field names. */
    public const array FIELDS = ['user', 'nice', 'system', 'irq', 'idle'];

    private const int STATES = 5;

    /**
     * @param array<int, array{0: int, 1: int}> $baselines [totals, idles] keyed by core (-1 = aggregate)
     * @param list<int> $fieldBaseline summed per-state ticks of the previous read
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly array $baselines,
        private readonly array $fieldBaseline,
        private readonly ?int $ncpu = null,
    ) {
    }

    public static function new(?Probe $probe = null): self
    {
        return new self($probe ?? LiveProbe::new(), [], []);
    }

    /**
     * @return array{0: CpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        $values = $this->probe->sysctl($this->ncpu !== null
            ? ['kern.boottime', 'kern.cp_times', 'vm.loadavg']
            : ['hw.ncpu', 'kern.boottime', 'kern.cp_times', 'vm.loadavg']);
        $ncpu = $this->ncpu ?? Sysctl::int($values, 'hw.ncpu');
        $boot = Sysctl::boottime($values);
        $load = Sysctl::loadavg($values) ?? [Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED];
        $uptime = $boot === null ? Sentinel::UNMEASURED : max(0.0, $this->probe->epoch() - $boot);
        $self = new self($this->probe, $this->baselines, $this->fieldBaseline, $ncpu);

        $times = Sysctl::ints($values, 'kern.cp_times');
        if ($ncpu !== null && $ncpu > 0 && count($times) > $ncpu * self::STATES) {
            $times = array_slice($times, 0, $ncpu * self::STATES);
        }
        $cores = intdiv(count($times), self::STATES);
        if ($cores < 1 || count($times) % self::STATES !== 0) {
            return [CpuSnapshot::unmeasured($load, $uptime), $self];
        }

        $baselines = $this->baselines;
        $summed = array_fill(0, self::STATES, 0);
        $percents = [];
        for ($core = 0; $core < $cores; $core++) {
            $row = array_slice($times, $core * self::STATES, self::STATES);
            foreach ($row as $i => $ticks) {
                $summed[$i] += $ticks;
            }
            [$percents[$core], $baselines[$core]] = $this->percent($core, array_sum($row), $row[4]);
        }
        [$total, $baselines[-1]] = $this->percent(-1, array_sum($summed), $summed[4]);

        $interval = array_sum($summed) - ($this->baselines[-1][0] ?? 0);
        $fields = [];
        foreach (self::FIELDS as $i => $name) {
            $fields[$name] = $interval > 0
                ? self::clamp(($summed[$i] - ($this->fieldBaseline[$i] ?? 0)) * 100.0 / $interval)
                : Sentinel::UNMEASURED;
        }

        return [
            new CpuSnapshot($total, $percents, $fields, $load, $uptime),
            new self($this->probe, $baselines, $summed, $ncpu),
        ];
    }

    /**
     * @return array{0: float, 1: array{0: int, 1: int}}
     */
    private function percent(int $core, int $totals, int $idles): array
    {
        $previous = $this->baselines[$core] ?? [0, 0];
        $interval = $totals - $previous[0];
        $percent = $interval > 0
            ? self::clamp(($interval - max(0, $idles - $previous[1])) * 100.0 / $interval)
            : Sentinel::UNMEASURED;

        return [$percent, [$totals, $idles]];
    }

    private static function clamp(float $percent): float
    {
        return max(0.0, min(100.0, $percent));
    }
}
