<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Aggregate and per-core CPU utilisation from /proc/stat jiffy deltas.
 *
 * Mirrors aristocratos/btop Cpu::collect (src/linux/btop_collect.cpp):
 *  - totals = sum of every field MINUS guest/guest_nice and anything after
 *    them — the kernel already folds guest time into user/nice, so adding
 *    it again would double-count virtualised load;
 *  - idles = idle + iowait — a core waiting on disk is not doing work, the
 *    same reading top/htop give;
 *  - steal stays in totals and outside idles, so a hypervisor stealing the
 *    core shows up as busy (the host really is unavailable to us);
 *  - per-field percentages (user, system, iowait, steal…) are each field's
 *    delta over the same totals denominator.
 *
 * The first sample has no previous reading; like btop (whose baselines
 * start at zero) it reports the since-boot average rather than a gap.
 * Unlike btop, a zero or negative interval (same jiffy read twice, or a
 * counter reset on CPU hotplug) yields UNMEASURED instead of btop's
 * max(1, Δ) artefact that paints a bogus 100% spike.
 *
 * Immutable: sample() returns the snapshot and the collector carrying the
 * new baseline.
 */
final class Cpu
{
    /** /proc/stat column order since kernel 2.6.33. */
    public const array FIELDS = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal', 'guest', 'guest_nice'];

    /**
     * @param array<int, array{0: int, 1: int}> $baselines [totals, idles] keyed by core (-1 = aggregate)
     * @param list<int>                          $fieldBaseline raw aggregate fields of the previous read
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly array $baselines,
        private readonly array $fieldBaseline,
    ) {
    }

    public static function new(?Paths $paths = null): self
    {
        return new self($paths ?? Paths::system(), [], []);
    }

    /**
     * @return array{0: CpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        $load = $this->readLoad();
        $uptime = $this->readUptime();

        $stat = Read::file($this->paths->proc('stat'));
        if ($stat === null) {
            // Keep the old baseline: the next good read then spans the gap.
            return [CpuSnapshot::unmeasured($load, $uptime), $this];
        }

        $baselines = $this->baselines;
        $fieldBaseline = $this->fieldBaseline;
        $total = Sentinel::UNMEASURED;
        $fields = [];
        $cores = [];

        foreach (explode("\n", $stat) as $line) {
            if (!str_starts_with($line, 'cpu')) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $label = array_shift($parts);
            $times = array_map('intval', $parts);
            if (count($times) < 4) {
                continue; // truncated mid-read: btop's "Malformed /proc/stat"
            }

            $core = $label === 'cpu' ? -1 : (int) substr((string) $label, 3);
            $totals = max(0, array_sum($times) - array_sum(array_slice($times, 8)));
            $idles = max(0, $times[3] + ($times[4] ?? 0));

            $previous = $this->baselines[$core] ?? [0, 0];
            $interval = $totals - $previous[0];
            $percent = $interval > 0
                ? self::clamp(($interval - max(0, $idles - $previous[1])) * 100.0 / $interval)
                : Sentinel::UNMEASURED;
            $baselines[$core] = [$totals, $idles];

            if ($core === -1) {
                $total = $percent;
                foreach (self::FIELDS as $i => $name) {
                    if (!isset($times[$i])) {
                        break;
                    }
                    $fields[$name] = $interval > 0
                        ? self::clamp(($times[$i] - ($this->fieldBaseline[$i] ?? 0)) * 100.0 / $interval)
                        : Sentinel::UNMEASURED;
                }
                $fieldBaseline = array_slice($times, 0, count(self::FIELDS));
            } else {
                $cores[$core] = $percent;
            }
        }

        // Offline/missing cpuN rows leave holes; the grid still needs a cell.
        $dense = [];
        $max = $cores === [] ? -1 : max(array_keys($cores));
        for ($i = 0; $i <= $max; $i++) {
            $dense[] = $cores[$i] ?? Sentinel::UNMEASURED;
        }

        return [
            new CpuSnapshot($total, $dense, $fields, $load, $uptime),
            new self($this->paths, $baselines, $fieldBaseline),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function readLoad(): array
    {
        $parts = preg_split('/\s+/', Read::line($this->paths->proc('loadavg')) ?? '') ?: [];
        $value = static fn (int $i): float => isset($parts[$i]) && is_numeric($parts[$i]) ? (float) $parts[$i] : Sentinel::UNMEASURED;

        return [$value(0), $value(1), $value(2)];
    }

    private function readUptime(): float
    {
        $first = explode(' ', Read::line($this->paths->proc('uptime')) ?? '')[0];

        return is_numeric($first) ? (float) $first : Sentinel::UNMEASURED;
    }

    private static function clamp(float $percent): float
    {
        return max(0.0, min(100.0, $percent));
    }
}
