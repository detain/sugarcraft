<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Which mounts the disks list keeps — btop Mem::collect's per-mount gate
 * (linux/btop_collect.cpp:2430-2520), shared by {@see Mounts} and the
 * deterministic fake so both obey one rule:
 *
 *  - `disks_filter`: whitespace-separated full mount points; only those
 *    are kept, or, when the FIRST token starts with `exclude=`, every
 *    mount except those (btop strips the prefix from token 0 only);
 *  - `zfs_hide_datasets`: a zfs device containing `/` (a dataset, not the
 *    pool) is dropped;
 *  - `use_fstab`: keep the mount points /etc/fstab lists (its second
 *    column, minus `none`/`swap`) and ignore `only_physical`;
 *  - otherwise `only_physical`: keep fstypes /proc/filesystems lists
 *    without `nodev` (+ zfs/wslfs/drvfs, minus squashfs/nullfs); off keeps
 *    every mount.
 *
 * The filter runs BEFORE statvfs (btop order), so excluding a hung network
 * share also stops it from being probed.
 */
final class MountSelection
{
    /**
     * @param list<string> $filter mount points named by disks_filter (prefix stripped)
     */
    private function __construct(
        public readonly bool $physicalOnly,
        public readonly bool $useFstab,
        public readonly array $filter,
        public readonly bool $exclude,
        public readonly bool $zfsHideDatasets,
    ) {
    }

    /** btop's defaults minus use_fstab: physical filesystems only, no filter. */
    public static function new(bool $physicalOnly = true, bool $useFstab = false, string $disksFilter = '', bool $zfsHideDatasets = false): self
    {
        $filter = preg_split('/\s+/', trim($disksFilter), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $exclude = false;
        if ($filter !== [] && str_starts_with($filter[0], 'exclude=')) {
            $exclude = true;
            $filter[0] = substr($filter[0], 8);
        }

        return new self($physicalOnly, $useFstab, array_values($filter), $exclude, $zfsHideDatasets);
    }

    /**
     * True when the mount passes the filter and the dataset rule — the
     * checks btop makes before the fstab / physical gate.
     */
    public function passesFilter(string $device, string $mountpoint, string $fstype): bool
    {
        if ($this->filter !== []) {
            $match = in_array($mountpoint, $this->filter, true);
            if ($this->exclude === $match) {
                return false;
            }
        }

        return !($this->zfsHideDatasets && $fstype === 'zfs' && str_contains($device, '/'));
    }

    /**
     * The whole gate.
     *
     * @param list<string>|null $physical fstypes counted as physical (null = not read)
     * @param list<string>|null $fstab    /etc/fstab mount points; null when unreadable
     */
    public function accepts(string $device, string $mountpoint, string $fstype, ?array $physical, ?array $fstab): bool
    {
        if (!$this->passesFilter($device, $mountpoint, $fstype)) {
            return false;
        }
        // Deviation: btop throws (and shows no disks) when /etc/fstab is
        // unreadable; here an unreadable fstab falls back to only_physical.
        if ($this->useFstab && $fstab !== null) {
            return in_array($mountpoint, $fstab, true);
        }

        return !$this->physicalOnly || in_array($fstype, $physical ?? [], true);
    }

    /**
     * Mount points of an /etc/fstab text: the second field of every
     * non-comment line, octal escapes decoded, `none` and `swap` skipped.
     *
     * @return list<string>
     */
    public static function fstab(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $cols = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($cols) < 2 || str_starts_with($cols[0], '#')) {
                continue;
            }
            $point = (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $cols[1]);
            if ($point !== 'none' && $point !== 'swap') {
                $out[] = $point;
            }
        }

        return $out;
    }
}
