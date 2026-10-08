<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Top\Collect\DiskIo;
use SugarCraft\Top\Collect\DiskIoSnapshot;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\Mounts;
use SugarCraft\Top\Collect\MountsSnapshot;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeDiskIo;
use SugarCraft\Top\Source\Fake\FakeMounts;
use SugarCraft\Top\Source\Source;

/**
 * The disks section's one Source: Mounts (space) and DiskIo (throughput)
 * sampled together, each mount paired with its block device.
 *
 * Pairing ports btop's stat-file walk (linux/btop_collect.cpp:2524-2545):
 * the mount's device is canonicalised (`/dev/mapper/root` -> `dm-0`) and
 * looked up by kernel name in /proc/diskstats — DiskIo runs with
 * only_physical OFF so partitions are listed with their own counters,
 * which is what btop's `/sys/block/<disk>/<part>/stat` reads. A name that
 * is not listed is shortened one character at a time, as btop does, until
 * a device matches (or fewer than two characters remain).
 *
 * Deviation: ZFS pool IO (`/proc/spl/kstat/zfs/<pool>/...`) is not read;
 * zfs mounts show space only.
 */
final class DisksSource implements Source
{
    /**
     * @param \Closure(string): string $kernelName mount device -> kernel block device name
     */
    private function __construct(
        private readonly Source $mounts,
        private readonly Source $io,
        private readonly \Closure $kernelName,
    ) {
    }

    /**
     * @param (\Closure(string): string)|null $kernelName defaults to basename(realpath($device))
     */
    public static function new(Source $mounts, Source $io, ?\Closure $kernelName = null): self
    {
        return new self($mounts, $io, $kernelName ?? self::canonical(...));
    }

    /** The live host. */
    public static function live(): self
    {
        return self::new(CollectorSource::of(Mounts::new()), CollectorSource::of(DiskIo::new(null, null, false)));
    }

    /** Deterministic fakes; the device name is its basename. */
    public static function fake(float $intervalSec = 2.0): self
    {
        return self::new(FakeMounts::new(), FakeDiskIo::new($intervalSec), static fn (string $d): string => basename($d));
    }

    /**
     * This source with the mounts collector re-gated by `$selection` —
     * asked at collect time from the CURRENT config. A mounts source that
     * cannot be retuned is kept as is.
     */
    public function withSelection(MountSelection $selection): self
    {
        $mounts = $this->mounts;
        if ($mounts instanceof FakeMounts) {
            $mounts = $mounts->withSelection($selection);
        } elseif ($mounts instanceof CollectorSource && ($c = $mounts->collector()) instanceof Mounts) {
            $mounts = CollectorSource::of($c->withSelection($selection));
        }

        return new self($mounts, $this->io, $this->kernelName);
    }

    public function mounts(): Source
    {
        return $this->mounts;
    }

    public function sample(): array
    {
        [$mounts, $nextMounts] = $this->mounts->sample();
        [$io, $nextIo] = $this->io->sample();
        $list = $mounts instanceof MountsSnapshot ? $mounts->mounts : [];
        $devices = $io instanceof DiskIoSnapshot ? $io->devices : [];
        $paired = [];
        foreach ($list as $mount) {
            $name = ($this->kernelName)($mount->device);
            while (strlen($name) >= 2 && !isset($devices[$name])) {
                $name = substr($name, 0, -1);
            }
            if (isset($devices[$name])) {
                $paired[$mount->mountpoint] = $devices[$name];
            }
        }

        return [new DisksSample($list, $paired), new self($nextMounts, $nextIo, $this->kernelName)];
    }

    private static function canonical(string $device): string
    {
        $real = str_starts_with($device, '/') ? @realpath($device) : false;

        return basename($real === false ? $device : $real);
    }
}
