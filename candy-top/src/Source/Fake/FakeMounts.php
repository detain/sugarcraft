<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\MountsSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see MountsSnapshot}s for a desktop-shaped host: an NVMe
 * root + EFI partition, a /home disk, a backup disk, plus a tmpfs and a
 * snap squashfs that only show with only_physical off. A fake /etc/fstab
 * lists the four real ones. Selection (disks_filter, use_fstab,
 * only_physical, zfs_hide_datasets) goes through the same
 * {@see MountSelection} gate as the live {@see \SugarCraft\Top\Collect\Mounts}.
 * Used space drifts slightly per sample.
 */
final class FakeMounts implements Source
{
    private const GIB = 1024 ** 3;

    /** fstypes the fake /proc/filesystems lists without `nodev`. */
    public const PHYSICAL = ['ext4', 'vfat', 'xfs', 'btrfs', 'zfs', 'wslfs', 'drvfs'];

    /** The fake /etc/fstab mount points. */
    public const FSTAB = ['/', '/boot/efi', '/home', '/mnt/backup'];

    /** device, mount point, fstype, total bytes, used % base. */
    private const ROWS = [
        ['/dev/nvme0n1p2', '/', 'ext4', 476 * self::GIB, 41.0],
        ['/dev/nvme0n1p1', '/boot/efi', 'vfat', self::GIB / 2, 7.0],
        ['tmpfs', '/tmp', 'tmpfs', 8 * self::GIB, 3.0],
        ['/dev/sda1', '/home', 'ext4', 1863 * self::GIB, 63.0],
        ['/dev/loop3', '/snap/core22/1380', 'squashfs', self::GIB / 16, 100.0],
        ['/dev/sdb1', '/mnt/backup', 'xfs', 3726 * self::GIB, 78.0],
    ];

    private function __construct(
        private readonly MountSelection $selection,
        private readonly int $step,
    ) {
    }

    public static function new(?MountSelection $selection = null): self
    {
        return new self($selection ?? MountSelection::new(), 0);
    }

    public function withSelection(MountSelection $selection): self
    {
        return new self($selection, $this->step);
    }

    public function selection(): MountSelection
    {
        return $this->selection;
    }

    public function sample(): array
    {
        $mounts = [];
        foreach (self::ROWS as $i => [$device, $point, $fstype, $total, $base]) {
            if (!$this->selection->accepts($device, $point, $fstype, self::PHYSICAL, self::FSTAB)) {
                continue;
            }
            $pct = min(100.0, $base + Wave::percent($this->step, $i * 0.9) * 0.02);
            $used = (int) ($total * $pct / 100);
            $name = $point === '/' ? 'root' : basename($point);
            $mounts[] = new Mount($device, $point, $fstype, $name, $total, $total - $used, $used);
        }

        return [new MountsSnapshot($mounts), new self($this->selection, $this->step + 1)];
    }
}
