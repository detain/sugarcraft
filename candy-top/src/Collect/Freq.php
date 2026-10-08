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
 * Stateless.
 */
final class Freq
{
    private function __construct(
        private readonly Paths $paths,
        private readonly FreqMode $mode,
    ) {
    }

    public static function new(?Paths $paths = null, FreqMode $mode = FreqMode::First): self
    {
        return new self($paths ?? Paths::system(), $mode);
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
        if ($mhz <= 1.0 || $mhz >= 999999999.0) {
            $mhz = Sentinel::UNMEASURED;
            $label = '';
        }

        $limit = static fn (string $file): float => $first !== null && ($v = Read::float($first . '/' . $file)) !== null && $v > 0.0
            ? $v / 1000.0
            : Sentinel::UNMEASURED;
        $boost = Read::int($base . '/boost');

        return [
            new FreqSnapshot(
                $mhz,
                $cores,
                $limit('scaling_min_freq'),
                $limit('scaling_max_freq'),
                $boost === null ? null : $boost === 1,
                $label ?? self::normalize($mhz),
            ),
            $this,
        ];
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

    private function cpuinfoMhz(): float
    {
        $raw = Read::file($this->paths->proc('cpuinfo')) ?? '';

        return preg_match('/^cpu MHz\s*:\s*([\d.]+)/mi', $raw, $m) === 1 ? (float) $m[1] : 0.0;
    }
}
