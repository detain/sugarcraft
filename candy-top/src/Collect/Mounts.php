<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Mounted filesystems with total/free/used space.
 *
 * Mirrors aristocratos/btop Mem::collect's disk list
 * (src/linux/btop_collect.cpp):
 *  - `only_physical` (default on) keeps fstypes /proc/filesystems lists
 *    without `nodev`, plus zfs/wslfs/drvfs, minus squashfs/nullfs — that
 *    drops tmpfs/proc/cgroup and snap images;
 *  - mount points carry octal escapes (`\040` for a space) that must be
 *    decoded before statvfs;
 *  - the first occurrence of a mount point wins (bind/over-mounts repeat);
 *  - free = blocks available to unprivileged users (btop
 *    `disk_free_priv=false`), which is exactly PHP's disk_free_space();
 *  - a mount whose statvfs fails is skipped, as btop's ignore_list does,
 *    so a dead NFS share is not probed every tick. Unlike btop (ignored
 *    for the process lifetime) the ignore is recoverable: it lapses after
 *    RETRY_AFTER seconds on the injected clock, and immediately when the
 *    mount point is remounted (its device or fstype changes);
 *  - a SUCCESSFUL probe cadences on the same RETRY_AFTER window: btop
 *    runs statvfs on a background future, and a hung network share costs
 *    however long it costs, so the loop now touches each mount at most
 *    once per window (successes included);
 *  - on stacked mounts (overlay/bind over the same point) the topmost
 *    entry's device/fstype pairs with the statvfs numbers, because that
 *    is what statvfs actually measured;
 *  - display name = basename of the mount point, "root" for "/".
 *
 * Selection (disks_filter, use_fstab, only_physical, zfs_hide_datasets)
 * is a {@see MountSelection}, retuned per sample by the disks panel with
 * {@see withSelection()}; /etc/fstab is read while use_fstab is on and
 * cached by its mtime, as btop's `fstab_time` does.
 *
 * Deviation: btop runs statvfs on a std::async future so a hung network
 * share cannot freeze the frame; PHP has no such primitive in this path,
 * so the $space closure is injectable and the cadence above caps how
 * often any mount is touched. A share that never answers therefore still
 * blocks the frame that first probes it — and note that the default
 * physical filter is not a rescue: FUSE types (sshfs, rclone, gocryptfs,
 * loop-backed images) register as nodev-free `fuseblk`, pass
 * only_physical, and hang exactly like NFS. Drive the probe through the
 * AsyncCommand seam if your host has such mounts.
 */
final class Mounts implements SelectableMounts
{
    private const array ALWAYS_PHYSICAL = ['zfs', 'wslfs', 'drvfs'];
    private const array NEVER_PHYSICAL = ['squashfs', 'nullfs'];

    /** Seconds a failed mount stays ignored before statvfs is tried again. */
    public const float RETRY_AFTER = 60.0;

    /**
     * @param \Closure(string): (array{0: int, 1: int}|null) $space mountpoint → [total, free] bytes
     * @param \Closure(): float                              $clock monotonic seconds
     * @param array<string, array{at: float, mount: string}>  $ignored failed mount points: when, and "device fstype"
     * @param array{0: int, 1: list<string>}|null            $fstab   cached /etc/fstab [mtime, mount points]
     * @param array<string, array{at: float, mount: string, total: int, free: int}> $probed successful probes: when, and the values
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $space,
        private readonly \Closure $clock,
        private readonly MountSelection $selection,
        private readonly array $ignored,
        private readonly ?array $fstab = null,
        private readonly array $probed = [],
    ) {
    }

    /**
     * @param (\Closure(string): (array{0: int, 1: int}|null))|null $space defaults to disk_total_space/disk_free_space
     * @param (\Closure(): float)|null $clock defaults to hrtime-based monotonic seconds
     */
    public static function new(?Paths $paths = null, ?\Closure $space = null, bool $physicalOnly = true, ?\Closure $clock = null): self
    {
        return new self(
            $paths ?? Paths::system(),
            $space ?? self::statvfs(...),
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            MountSelection::new($physicalOnly),
            [],
            null,
            [],
        );
    }

    /** The same collector (ignore list kept) under another disks selection. */
    public function withSelection(MountSelection $selection): self
    {
        return new self($this->paths, $this->space, $this->clock, $selection, $this->ignored, $this->fstab, $this->probed);
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
        $raw = Read::file($this->paths->proc('mounts'));
        if ($raw === null) {
            return [new MountsSnapshot([]), $this];
        }

        $now = ($this->clock)();
        $sel = $this->selection;
        $cache = $sel->useFstab ? $this->readFstab() : $this->fstab;
        $fstab = $sel->useFstab ? $cache[1] ?? null : null;
        $fstypes = $sel->physicalOnly && $fstab === null ? $this->physicalFsTypes() : null;
        $ignored = [];
        $probed = [];
        $mounts = [];
        $order = [];
        $tops = [];

        // Last line per mount point wins (candy-top-056): statvfs below
        // measures the TOP of a stacked (overlay/bind) mount, so the
        // shadowed lower layer's device/fstype must not pair with the
        // top's numbers. Order keeps the first appearance; identity the
        // topmost mount.
        foreach (explode("\n", $raw) as $line) {
            $cols = explode(' ', trim($line));
            if (count($cols) < 3) {
                continue;
            }
            [$device, $mountpoint, $fstype] = [self::unescape($cols[0]), self::unescape($cols[1]), $cols[2]];
            if (!isset($tops[$mountpoint])) {
                $order[] = $mountpoint;
            }
            $tops[$mountpoint] = [$device, $fstype];
        }

        foreach ($order as $mountpoint) {
            [$device, $fstype] = $tops[$mountpoint];
            if (!$sel->accepts($device, $mountpoint, $fstype, $fstypes, $fstab)) {
                continue;
            }

            $signature = $device . ' ' . $fstype;
            $failed = $this->ignored[$mountpoint] ?? null;
            if ($failed !== null && $failed['mount'] === $signature && $now - $failed['at'] < self::RETRY_AFTER) {
                $ignored[$mountpoint] = $failed;
                if (isset($this->probed[$mountpoint])) {
                    $probed[$mountpoint] = $this->probed[$mountpoint];
                }
                continue;
            }

            $last = $this->probed[$mountpoint] ?? null;
            if ($last !== null && $last['mount'] === $signature && $now - $last['at'] < self::RETRY_AFTER) {
                // candy-top-031: a hung mount costs whatever it costs per
                // touch, so a successful probe is bound by the same cadence
                // as the retry window — statvfs runs at most once per
                // RETRY_AFTER seconds per mount, successes included.
                [$total, $free] = [$last['total'], $last['free']];
                $probed[$mountpoint] = $last;
            } else {
                $space = ($this->space)($mountpoint);
                if ($space === null) {
                    $ignored[$mountpoint] = ['at' => $now, 'mount' => $signature];
                    continue;
                }
                [$total, $free] = $space;
                $probed[$mountpoint] = ['at' => $now, 'mount' => $signature, 'total' => $total, 'free' => $free];
            }
            $name = $mountpoint === '/' ? 'root' : basename($mountpoint);

            $mount = new Mount($device, $mountpoint, $fstype, $name, $total, $free, max(0, $total - $free));
            if ($mountpoint === '/') {
                array_unshift($mounts, $mount);
            } else {
                $mounts[] = $mount;
            }
        }

        // Ignores for mount points that disappeared are dropped with them.
        return [new MountsSnapshot($mounts), new self($this->paths, $this->space, $this->clock, $this->selection, $ignored, $cache, $probed)];
    }

    /**
     * /etc/fstab's mount points, re-parsed only when its mtime changed
     * (btop `fs::last_write_time("/etc/fstab") != fstab_time`); null when
     * unreadable.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    private function readFstab(): ?array
    {
        $path = $this->paths->path('/etc/fstab');
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        if ($this->fstab !== null && $this->fstab[0] === $mtime) {
            return $this->fstab;
        }
        $text = Read::file($path);

        return $text === null ? null : [$mtime, MountSelection::fstab($text)];
    }

    /**
     * @return list<string>
     */
    private function physicalFsTypes(): array
    {
        $types = self::ALWAYS_PHYSICAL;
        foreach (explode("\n", Read::file($this->paths->proc('filesystems')) ?? '') as $line) {
            if ($line === '' || str_starts_with($line, 'nodev')) {
                continue;
            }
            $type = trim($line);
            if (!in_array($type, self::NEVER_PHYSICAL, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** Decode the kernel's \ooo octal escapes (space, tab, newline, backslash). */
    private static function unescape(string $field): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-7]{3})/',
            // The kernel escapes with three octal digits, but a malformed
            // fstab/fixture line can carry \777 = 511, and chr() throws on
            // it (candy-top-048: one bad line must not kill the disk box
            // every sample). Clamp to the byte the field could ever mean.
            static fn (array $m): string => chr(((int) octdec($m[1])) & 0xFF),
            $field,
        );
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function statvfs(string $mountpoint): ?array
    {
        $total = @disk_total_space($mountpoint);
        $free = @disk_free_space($mountpoint);
        if ($total === false || $free === false) {
            return null;
        }

        return [(int) $total, (int) $free];
    }
}
