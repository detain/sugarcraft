<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\Freq as LinuxFreq;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\FreqSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\TunableFreq;

/**
 * CPU frequency from cpufreq(4) sysctls.
 *
 * Mirrors aristocratos/btop Cpu::get_cpuHz (src/freebsd/btop_collect.cpp):
 * `dev.cpu.0.freq` in MHz; absent (no est/hwpstate/cpufreq driver loaded,
 * a VM, the reference Atom) → UNMEASURED with label "" — the sentinel
 * law, never a failure. Labels go through the shared
 * {@see LinuxFreq::label()} (btop #1792 format_frequency_mhz), so the BSD
 * box shows units exactly like Linux.
 *
 * Extras over btop: min/max from `dev.cpu.0.freq_levels` ("MHz/mW" pairs,
 * highest first); per-core (btop #1785, opt-in via withPerCore) from the
 * whole `dev.cpu` subtree — cpufreq often attaches to cpu0 only, so a core
 * without its own `freq` reads UNMEASURED. freq_mode collapses the
 * per-core readings when they were read; otherwise cpu0 is the only
 * reading and every mode returns it. boost is null (no global switch).
 *
 * Cost: discovery reads `hw.ncpu` + the `dev.cpu` subtree once and
 * keeps the static parts (core count, freq_levels, which cpus publish a
 * `freq` at all); later samples ask only those `freq` OIDs — cpu0's, or
 * every one with withPerCore — in one sysctl child, and none at all on a
 * host without cpufreq. A discovery that got no answer is retried.
 */
final class Freq implements TunableFreq
{
    /**
     * @param array{ncpu: int, levels: array<int, array{0: float, 1: float}>, freq: list<int>}|null $static discovered once
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly FreqMode $mode,
        private readonly bool $perCore,
        private readonly ?array $static = null,
    ) {
    }

    public static function new(?Probe $probe = null, FreqMode $mode = FreqMode::First, bool $perCore = false): self
    {
        return new self($probe ?? LiveProbe::new(), $mode, $perCore);
    }

    public function withPerCore(bool $perCore): self
    {
        return $perCore === $this->perCore ? $this : new self($this->probe, $this->mode, $perCore, $this->static);
    }

    /**
     * @return array{0: FreqSnapshot, 1: self}
     */
    public function sample(): array
    {
        $self = $this;
        if ($this->static === null) {
            $v = $this->probe->sysctl(['hw.ncpu', 'dev.cpu']);
            $self = $v === [] ? $this : new self($this->probe, $this->mode, $this->perCore, self::discover($v));
        } else {
            $oids = array_map(static fn (int $i): string => "dev.cpu.{$i}.freq", $this->perCore ? $this->static['freq'] : array_intersect($this->static['freq'], [0]));
            $v = $oids === [] ? [] : $this->probe->sysctl(array_values($oids));
        }
        $static = $self->static ?? ['ncpu' => 1, 'levels' => [], 'freq' => []];
        [$min0, $max0] = $static['levels'][0] ?? [Sentinel::UNMEASURED, Sentinel::UNMEASURED];
        $cpu0 = self::plausible((float) (Sysctl::int($v, 'dev.cpu.0.freq') ?? 0));

        $perCore = $perMin = $perMax = [];
        if ($this->perCore) {
            for ($i = 0; $i < $static['ncpu']; $i++) {
                $perCore[$i] = self::plausible((float) (Sysctl::int($v, "dev.cpu.{$i}.freq") ?? 0));
                [$perMin[$i], $perMax[$i]] = $static['levels'][$i] ?? [$min0, $max0];
            }
        }

        $measured = array_values(array_filter($perCore, static fn (float $f): bool => $f > 0.0));
        $cores = $measured !== [] ? $measured : ($cpu0 > 0.0 ? [$cpu0] : []);
        $mhz = match (true) {
            $cores === [] => Sentinel::UNMEASURED,
            $this->mode === FreqMode::First => $cores[0],
            $this->mode === FreqMode::Average => array_sum($cores) / count($cores),
            $this->mode === FreqMode::Highest => max($cores),
            default => min($cores),
        };
        $label = $cores !== [] && $this->mode === FreqMode::Range
            ? LinuxFreq::normalize(min($cores)) . ' - ' . LinuxFreq::normalize(max($cores))
            : LinuxFreq::label($mhz);

        return [new FreqSnapshot($mhz, $cores, $min0, $max0, null, $label, $perCore, $perMin, $perMax), $self];
    }

    /**
     * @param array<string, string> $v
     * @return array{ncpu: int, levels: array<int, array{0: float, 1: float}>, freq: list<int>}
     */
    private static function discover(array $v): array
    {
        $levels = [];
        $freq = [];
        foreach ($v as $oid => $value) {
            if (preg_match('/^dev\.cpu\.(\d+)\.(freq|freq_levels)$/', $oid, $m) !== 1) {
                continue;
            }
            if ($m[2] === 'freq') {
                $freq[] = (int) $m[1];
            } elseif (($l = self::levels($value))[1] > 0.0) {
                $levels[(int) $m[1]] = $l;
            }
        }
        sort($freq);

        return ['ncpu' => max(1, Sysctl::int($v, 'hw.ncpu') ?? 1), 'levels' => $levels, 'freq' => $freq];
    }

    /**
     * "1800/-1 1575/-1 1350/-1" → [min, max] MHz; UNMEASURED both when empty.
     *
     * @return array{0: float, 1: float}
     */
    public static function levels(string $text): array
    {
        $mhz = [];
        foreach (preg_split('/\s+/', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            $f = (int) explode('/', $pair, 2)[0];
            if ($f > 1) {
                $mhz[] = (float) $f;
            }
        }

        return $mhz === [] ? [Sentinel::UNMEASURED, Sentinel::UNMEASURED] : [min($mhz), max($mhz)];
    }

    /** btop get_cpuHz's failed-read rule, as in the Linux collector. */
    private static function plausible(float $mhz): float
    {
        return $mhz <= 1.0 || $mhz >= 999999999.0 ? Sentinel::UNMEASURED : $mhz;
    }
}
