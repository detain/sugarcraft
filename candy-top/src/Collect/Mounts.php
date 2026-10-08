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
 *  - display name = basename of the mount point, "root" for "/".
 *
 * Deviation: btop runs statvfs on a std::async future so a hung network
 * share cannot freeze the frame; PHP has no such primitive here, so the
 * $space closure is injectable and callers that mount network shares
 * should keep only_physical on.
 */
final class Mounts
{
    private const array ALWAYS_PHYSICAL = ['zfs', 'wslfs', 'drvfs'];
    private const array NEVER_PHYSICAL = ['squashfs', 'nullfs'];

    /** Seconds a failed mount stays ignored before statvfs is tried again. */
    public const float RETRY_AFTER = 60.0;

    /**
     * @param \Closure(string): (array{0: int, 1: int}|null) $space mountpoint → [total, free] bytes
     * @param \Closure(): float                              $clock monotonic seconds
     * @param array<string, array{at: float, mount: string}>  $ignored failed mount points: when, and "device fstype"
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $space,
        private readonly \Closure $clock,
        private readonly bool $physicalOnly,
        private readonly array $ignored,
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
            $physicalOnly,
            [],
        );
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
        $fstypes = $this->physicalOnly ? $this->physicalFsTypes() : null;
        $ignored = [];
        $seen = [];
        $mounts = [];

        foreach (explode("\n", $raw) as $line) {
            $cols = explode(' ', trim($line));
            if (count($cols) < 3) {
                continue;
            }
            [$device, $mountpoint, $fstype] = [self::unescape($cols[0]), self::unescape($cols[1]), $cols[2]];
            if (isset($seen[$mountpoint])) {
                continue;
            }
            if ($fstypes !== null && !in_array($fstype, $fstypes, true)) {
                continue;
            }
            $seen[$mountpoint] = true;

            $signature = $device . ' ' . $fstype;
            $failed = $this->ignored[$mountpoint] ?? null;
            if ($failed !== null && $failed['mount'] === $signature && $now - $failed['at'] < self::RETRY_AFTER) {
                $ignored[$mountpoint] = $failed;
                continue;
            }

            $space = ($this->space)($mountpoint);
            if ($space === null) {
                $ignored[$mountpoint] = ['at' => $now, 'mount' => $signature];
                continue;
            }
            [$total, $free] = $space;
            $name = $mountpoint === '/' ? 'root' : basename($mountpoint);

            $mount = new Mount($device, $mountpoint, $fstype, $name, $total, $free, max(0, $total - $free));
            if ($mountpoint === '/') {
                array_unshift($mounts, $mount);
            } else {
                $mounts[] = $mount;
            }
        }

        // Ignores for mount points that disappeared are dropped with them.
        return [new MountsSnapshot($mounts), new self($this->paths, $this->space, $this->clock, $this->physicalOnly, $ignored)];
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
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $field);
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
