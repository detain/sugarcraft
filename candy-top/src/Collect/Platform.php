<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Source;

/**
 * The host-OS collector selector: the one place that knows which
 * `Collect\*` family reads this machine.
 *
 * btop picks its collector at compile time (src/linux/, src/freebsd/,
 * src/osx/ ... — one btop_collect.cpp per OS). PHP decides at run time
 * from PHP_OS: "FreeBSD" gets {@see FreeBsd} (sysctl + base tools); every
 * other OS keeps the Linux /proc + /sys collectors, which already degrade
 * to sentinels where those trees are missing. Both families produce the
 * SAME snapshot types, so panels are platform-blind.
 *
 * Every factory returns a {@see CollectorSource} around the concrete
 * collector. The collectors a panel retunes at collect time implement a
 * shared interface on BOTH families — {@see TunableFreq},
 * {@see SelectableBattery}, {@see SelectableMounts},
 * {@see TunableProcList} — so a panel that checks
 * `CollectorSource::collector() instanceof <interface>` (not the Linux
 * class) retunes either host. Defaults mirror the live wiring they
 * replace: diskIo() is unfiltered (DisksSource::live() pairs mounts with
 * partitions), mounts() is only_physical, memory() counts the ARC.
 */
final class Platform
{
    private function __construct(
        public readonly string $os,
        private readonly ?FreeBsd\Probe $probe,
    ) {
    }

    /** The running host (PHP_OS). */
    public static function detect(): self
    {
        return self::for(PHP_OS);
    }

    /**
     * @param string            $os    a PHP_OS value ("Linux", "FreeBSD", "Darwin", ...)
     * @param FreeBsd\Probe|null $probe the FreeBSD probe; null = the live host
     */
    public static function for(string $os, ?FreeBsd\Probe $probe = null): self
    {
        $freeBsd = strcasecmp($os, 'FreeBSD') === 0;

        return new self($os, $freeBsd ? ($probe ?? FreeBsd\LiveProbe::new()) : $probe);
    }

    public function isFreeBsd(): bool
    {
        return strcasecmp($this->os, 'FreeBSD') === 0;
    }

    public function hostInfo(): HostInfo
    {
        return $this->isFreeBsd() ? FreeBsd\HostDetect::detect($this->probe()) : HostInfo::detect();
    }

    public function cpu(): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Cpu::new($this->probe()) : Cpu::new());
    }

    public function memory(bool $zfsArcCached = true): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Memory::new($this->probe(), $zfsArcCached) : Memory::new(null, $zfsArcCached));
    }

    public function net(?string $iface = null): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Net::new($this->probe(), $iface) : Net::new(null, null, $iface));
    }

    /**
     * Per-device IO. Unfiltered by default, as DisksSource::live() builds
     * it: the disks section pairs each mount with its partition's counters.
     */
    public function diskIo(bool $physicalOnly = false): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\DiskIo::new($this->probe(), $physicalOnly) : DiskIo::new(null, null, $physicalOnly));
    }

    public function mounts(bool $physicalOnly = true): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Mounts::new($this->probe(), $physicalOnly) : Mounts::new(null, null, $physicalOnly));
    }

    public function procList(): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\ProcList::new($this->probe()) : ProcList::new());
    }

    public function freq(FreqMode $mode = FreqMode::First, bool $perCore = false): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Freq::new($this->probe(), $mode, $perCore) : Freq::new(null, $mode, $perCore));
    }

    public function temp(?string $cpuSensor = null): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Temp::new($this->probe(), $cpuSensor) : Temp::new(null, $cpuSensor));
    }

    public function battery(?string $battery = null): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? FreeBsd\Battery::new($this->probe(), $battery) : Battery::new(null, $battery));
    }

    /**
     * GPUs and NPUs ({@see Gpu\Accelerators}): nvidia-smi everywhere, plus
     * the amdgpu / i915 / xe / intel_vpu / amdxdna sysfs backends on
     * hosts with Linux sysfs. FreeBSD gets nvidia-smi only (its drm-kmod
     * exposes no amdgpu/i915 sysfs nodes). Per-process collection starts
     * off; retune with `collector()->withProcesses()`.
     */
    public function gpu(): Source
    {
        return CollectorSource::of($this->isFreeBsd() ? Gpu\Accelerators::nvidiaOnly() : Gpu\Accelerators::detect());
    }

    private function probe(): FreeBsd\Probe
    {
        return $this->probe ?? FreeBsd\LiveProbe::new();
    }
}
