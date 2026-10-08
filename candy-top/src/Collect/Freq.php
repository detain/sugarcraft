<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * CPU frequency from cpufreq policies, falling back to /proc/cpuinfo.
 *
 * Mirrors aristocratos/btop Cpu::get_cpuHz + normalize_frequency
 * (src/linux/btop_collect.cpp):
 *  - per-policy /sys/devices/system/cpu/cpufreq/policyN/scaling_cur_freq
 *    in kHz ÷ 1000 → MHz; non-positive readings are dropped;
 *  - collapsed per freq_mode (default "first");
 *  - no usable policy → the first "cpu MHz" line of /proc/cpuinfo
 *    (VMs and some ARM boards have no cpufreq);
 *  - a result ≤ 1 MHz or absurdly large is a failed read → UNMEASURED,
 *    label "";
 *  - label: > 999 MHz → "x.y GHz" truncated to three characters with a
 *    trailing dot dropped ("3.4 GHz", "12 GHz"); else "N MHz".
 *
 * Adds btop's info-only extras the options/detail views want: the
 * scaling_min/max limits of policy0 and the global `boost` switch.
 *
 * Per-core (btop #1785, opt-in via withPerCore() — show_core_freq
 * "value"/"graph"; off by default so a 256-core host does not pay 768
 * extra sysfs reads per tick): /sys/devices/system/cpu/cpuN/cpufreq/
 * scaling_{cur,min,max}_freq per logical cpu, the kernel-ABI path (on ARM
 * cpuN/cpufreq symlinks to the shared policy, which is what fixed btop's
 * #1288-class crash). A cpu without cpufreq (offline, VM), or whose
 * reading fails the same ≤ 1 MHz / ≥ 999999999 MHz rule as the aggregate, reads
 * UNMEASURED; when no cpu has a readable cur_freq at all, the "cpu MHz"
 * of each /proc/cpuinfo processor block is used instead. The aggregate
 * `mhz`/`label` keep the policy semantics above either way, so labels do
 * not move when the option flips.
 *
 * Stateless.
 */
final class Freq
{
    private function __construct(
        private readonly Paths $paths,
        private readonly FreqMode $mode,
        private readonly bool $perCore = false,
    ) {
    }

    public static function new(?Paths $paths = null, FreqMode $mode = FreqMode::First, bool $perCore = false): self
    {
        return new self($paths ?? Paths::system(), $mode, $perCore);
    }

    /** Toggle the per-core read (btop show_core_freq != "off"). */
    public function withPerCore(bool $perCore): self
    {
        return $perCore === $this->perCore ? $this : new self($this->paths, $this->mode, $perCore);
    }

    /**
     * @return array{0: FreqSnapshot, 1: self}
     */
    public function sample(): array
    {
        $base = $this->paths->sys('devices/system/cpu/cpufreq');
        $cores = [];
        $first = null;
        foreach (Read::entries($base) as $entry) {
            if (preg_match('/^policy\d+$/', $entry) !== 1) {
                continue;
            }
            $first ??= $base . '/' . $entry;
            $khz = Read::float($base . '/' . $entry . '/scaling_cur_freq');
            if ($khz !== null && $khz > 0.0) {
                $cores[] = $khz / 1000.0;
            }
        }

        $label = null;
        $mhz = match (true) {
            $cores === [] => 0.0,
            $this->mode === FreqMode::First => $cores[0],
            $this->mode === FreqMode::Average => array_sum($cores) / count($cores),
            $this->mode === FreqMode::Highest => max($cores),
            default => min($cores),
        };
        if ($cores !== [] && $this->mode === FreqMode::Range) {
            $label = self::normalize(min($cores)) . ' - ' . self::normalize(max($cores));
        }
        if ($mhz <= 0.0) {
            $mhz = $this->cpuinfoMhz();
        }
        if (self::plausible($mhz) < 0.0) {
            $mhz = Sentinel::UNMEASURED;
            $label = '';
        }

        $limit = static fn (string $file): float => $first !== null && ($v = Read::float($first . '/' . $file)) !== null && $v > 0.0
            ? $v / 1000.0
            : Sentinel::UNMEASURED;
        $boost = Read::int($base . '/boost');
        [$perCur, $perMin, $perMax] = $this->perCore ? $this->readPerCore() : [[], [], []];

        return [
            new FreqSnapshot(
                $mhz,
                $cores,
                $limit('scaling_min_freq'),
                $limit('scaling_max_freq'),
                $boost === null ? null : $boost === 1,
                $label ?? self::label($mhz),
                $perCur,
                $perMin,
                $perMax,
            ),
            $this,
        ];
    }

    /**
     * The one frequency formatter, shared by the aggregate label and the
     * per-core grid. Mirrors btop #1792 Tools::format_frequency_mhz
     * exactly: "" for `mhz <= 0 || !isfinite(mhz)` (which covers the
     * UNMEASURED sentinel), else normalize(). The stricter "≤ 1 MHz or
     * ≥ 999999999 is a failed read" rule is collector policy (btop
     * Cpu::get_cpuHz), applied in sample() before a value ever reaches the
     * formatter — it is not a formatting rule, so it does not live here.
     */
    public static function label(float $mhz): string
    {
        return $mhz <= 0.0 || !is_finite($mhz) ? '' : self::normalize($mhz);
    }

    /** btop normalize_frequency, input in MHz. */
    public static function normalize(float $mhz): string
    {
        foreach ([[999999.0, 1e6, 'THz'], [999.0, 1e3, 'GHz']] as [$above, $div, $unit]) {
            if ($mhz > $above) {
                $text = substr(sprintf('%.1f', $mhz / $div), 0, 3);

                return rtrim($text, '.') . ' ' . $unit;
            }
        }

        return sprintf('%.0f MHz', $mhz);
    }

    /**
     * @return array{0: array<int, float>, 1: array<int, float>, 2: array<int, float>} cur/min/max MHz by cpu index
     */
    private function readPerCore(): array
    {
        $base = $this->paths->sys('devices/system/cpu');
        $cur = $min = $max = [];
        $mhz = static function (string $file): float {
            $khz = Read::float($file);

            return $khz !== null ? self::plausible($khz / 1000.0) : Sentinel::UNMEASURED;
        };
        foreach (Read::entries($base) as $entry) {
            if (preg_match('/^cpu(\d+)$/', $entry, $m) !== 1) {
                continue;
            }
            $dir = $base . '/' . $entry . '/cpufreq/';
            $cur[(int) $m[1]] = $mhz($dir . 'scaling_cur_freq');
            $min[(int) $m[1]] = $mhz($dir . 'scaling_min_freq');
            $max[(int) $m[1]] = $mhz($dir . 'scaling_max_freq');
        }
        ksort($cur);
        ksort($min);
        ksort($max);

        if (array_filter($cur, static fn (float $v): bool => $v >= 0.0) === []) {
            $fallback = $this->cpuinfoPerProcessor();
            if ($fallback !== []) {
                $cur = $fallback + array_fill_keys(array_keys($cur), Sentinel::UNMEASURED);
                ksort($cur);
                $min += array_fill_keys(array_keys($cur), Sentinel::UNMEASURED);
                $max += array_fill_keys(array_keys($cur), Sentinel::UNMEASURED);
                ksort($min);
                ksort($max);
            }
        }

        return [$cur, $min, $max];
    }

    /**
     * btop Cpu::get_cpuHz's failed-read rule — ≤ 1 MHz or ≥ 999999999 MHz
     * is not a frequency — shared by the aggregate and every per-core
     * value so the two can never disagree about the same reading.
     */
    private static function plausible(float $mhz): float
    {
        return $mhz <= 1.0 || $mhz >= 999999999.0 || !is_finite($mhz) ? Sentinel::UNMEASURED : $mhz;
    }

    /**
     * @return array<int, float> processor index → "cpu MHz"
     */
    private function cpuinfoPerProcessor(): array
    {
        $raw = Read::file($this->paths->proc('cpuinfo')) ?? '';
        $out = [];
        foreach (preg_split('/\n\s*\n/', $raw) ?: [] as $block) {
            if (preg_match('/^processor\s*:\s*(\d+)/mi', $block, $p) === 1) {
                $out[(int) $p[1]] = preg_match('/^cpu MHz\s*:\s*([\d.]+)/mi', $block, $m) === 1
                    ? self::plausible((float) $m[1])
                    : Sentinel::UNMEASURED;
            }
        }

        return $out;
    }

    private function cpuinfoMhz(): float
    {
        $raw = Read::file($this->paths->proc('cpuinfo')) ?? '';

        return preg_match('/^cpu MHz\s*:\s*([\d.]+)/mi', $raw, $m) === 1 ? (float) $m[1] : 0.0;
    }
}
