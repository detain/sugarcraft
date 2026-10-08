<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\MountsSnapshot;
use SugarCraft\Top\Collect\SelectableMounts;

/**
 * Mounted filesystems with total/free/used space, from `mount -p` plus
 * statvfs.
 *
 * Mirrors aristocratos/btop Mem::collect's disk list
 * (src/freebsd/btop_collect.cpp), which walks getmntinfo(3):
 *  - `mount -p` prints the same statfs table in fstab(5) form (device,
 *    mount point, fstype, ...), octal escapes (\040) decoded;
 *  - btop skips the in-memory filesystems (autofs devfs fdescfs linprocfs
 *    linsysfs procfs tmpfs); here that list, plus nullfs/unionfs bind
 *    mounts, mqueuefs and the network filesystems the Linux port's
 *    `nodev` rule drops, is what only_physical removes — the same
 *    {@see MountSelection} gate (disks_filter, use_fstab,
 *    zfs_hide_datasets) the Linux collector uses;
 *  - space from statvfs (PHP disk_total_space / disk_free_space), free =
 *    blocks available to unprivileged users, as on Linux;
 *  - "/" first, display name "root" for "/", else the basename;
 *  - a mount whose statvfs fails is ignored for RETRY_AFTER seconds, or
 *    until it is remounted, as {@see \SugarCraft\Top\Collect\Mounts} does.
 *
 * One mount child per sample.
 */
final class Mounts implements SelectableMounts
{
    /** Never storage: btop's in-memory list + bind/union mounts + network shares. */
    public const array NOT_PHYSICAL = [
        'autofs', 'devfs', 'fdescfs', 'linprocfs', 'linsysfs', 'procfs', 'tmpfs',
        'nullfs', 'unionfs', 'mqueuefs', 'nfs', 'smbfs', 'fusefs.sshfs',
    ];

    public const float RETRY_AFTER = 60.0;

    /**
     * @param array<string, array{at: float, mount: string}> $ignored
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly MountSelection $selection,
        private readonly array $ignored,
    ) {
    }

    public static function new(?Probe $probe = null, bool $physicalOnly = true): self
    {
        return new self($probe ?? LiveProbe::new(), MountSelection::new($physicalOnly), []);
    }

    public function withSelection(MountSelection $selection): self
    {
        return new self($this->probe, $selection, $this->ignored);
    }

    public function selection(): MountSelection
    {
        return $this->selection;
    }

    /**
     * @return array{0: MountsSnapshot, 1: self}
     */
    public function sample(): array
    {
        $raw = $this->probe->run(['mount', '-p']);
        if ($raw === null) {
            return [new MountsSnapshot([]), $this];
        }
        $now = $this->probe->monotonic();
        $sel = $this->selection;
        $fstabText = $sel->useFstab ? $this->probe->file('/etc/fstab') : null;
        $fstab = $fstabText === null ? null : MountSelection::fstab($fstabText);

        $table = self::parse($raw);
        $physical = array_values(array_diff(array_unique(array_column($table, 2)), self::NOT_PHYSICAL));
        $ignored = [];
        $seen = [];
        $mounts = [];
        foreach ($table as [$device, $mountpoint, $fstype]) {
            if (isset($seen[$mountpoint]) || !$sel->accepts($device, $mountpoint, $fstype, $physical, $fstab)) {
                continue;
            }
            $seen[$mountpoint] = true;

            $signature = $device . ' ' . $fstype;
            $failed = $this->ignored[$mountpoint] ?? null;
            if ($failed !== null && $failed['mount'] === $signature && $now - $failed['at'] < self::RETRY_AFTER) {
                $ignored[$mountpoint] = $failed;
                continue;
            }
            $space = $this->probe->space($mountpoint);
            if ($space === null) {
                $ignored[$mountpoint] = ['at' => $now, 'mount' => $signature];
                continue;
            }
            [$total, $free] = $space;
            $mount = new Mount($device, $mountpoint, $fstype, $mountpoint === '/' ? 'root' : basename($mountpoint), $total, $free, max(0, $total - $free));
            if ($mountpoint === '/') {
                array_unshift($mounts, $mount);
            } else {
                $mounts[] = $mount;
            }
        }

        return [new MountsSnapshot($mounts), new self($this->probe, $this->selection, $ignored)];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [device, mount point, fstype] in table order
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $cols = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($cols) < 3 || str_starts_with($cols[0], '#')) {
                continue;
            }
            $out[] = [self::unescape($cols[0]), self::unescape($cols[1]), $cols[2]];
        }

        return $out;
    }

    private static function unescape(string $field): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $field);
    }
}
