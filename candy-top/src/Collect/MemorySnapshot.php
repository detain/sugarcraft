<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * btop's memory classes in bytes. Every field is Sentinel::UNMEASURED_INT
 * when /proc/meminfo could not be read; swap fields are 0 on a swapless
 * host (that is a measurement, not a gap).
 *
 * `zswap` / `zswapped` (btop #1739): the compressed zswap pool in RAM and
 * the uncompressed size of the pages it holds, from meminfo `Zswap:` /
 * `Zswapped:`; UNMEASURED_INT on kernels < 5.19 or when meminfo is
 * unreadable. Pages in zswap count as swap_used (the kernel charges them
 * to SwapTotal − SwapFree) although they never touched the disk.
 */
final class MemorySnapshot
{
    public function __construct(
        public readonly int $total,
        public readonly int $used,
        public readonly int $available,
        public readonly int $cached,
        public readonly int $free,
        public readonly int $swapTotal,
        public readonly int $swapUsed,
        public readonly int $swapFree,
        public readonly int $zswap = Sentinel::UNMEASURED_INT,
        public readonly int $zswapped = Sentinel::UNMEASURED_INT,
    ) {
    }

    public static function unmeasured(): self
    {
        $u = Sentinel::UNMEASURED_INT;

        return new self($u, $u, $u, $u, $u, $u, $u, $u);
    }

    public function measured(): bool
    {
        return $this->total > 0;
    }

    /**
     * Percent of MemTotal for a class ('used', 'available', 'cached',
     * 'free'), rounded the way btop rounds; UNMEASURED when unknown.
     */
    public function percent(string $class): float
    {
        $value = match ($class) {
            'used' => $this->used,
            'available' => $this->available,
            'cached' => $this->cached,
            'free' => $this->free,
            default => Sentinel::UNMEASURED_INT,
        };

        return $this->total > 0 && $value >= 0 ? round($value * 100.0 / $this->total) : Sentinel::UNMEASURED;
    }

    /** Whether the kernel reported zswap at all (it may still be 0 bytes). */
    public function hasZswap(): bool
    {
        return $this->zswap >= 0;
    }

    /**
     * btop show_zswap "Used": swap in use minus the pages zswap holds in
     * RAM, i.e. what actually sits on the swap device. Falls back to plain
     * swapUsed when zswap is unmeasured; clamped at 0 because the two
     * meminfo lines are not read atomically.
     */
    public function swapUsedOnDisk(): int
    {
        if ($this->swapUsed < 0 || $this->zswapped < 0) {
            return $this->swapUsed;
        }

        return max(0, $this->swapUsed - $this->zswapped);
    }

    /**
     * Percent of SwapTotal for 'swap_used' / 'swap_free', plus
     * 'swap_used_disk' (swapUsedOnDisk) and 'zswap' (the compressed pool —
     * btop's swap_zswap_compressed meter); UNMEASURED without swap or when
     * the class is unmeasured.
     */
    public function swapPercent(string $class): float
    {
        $value = match ($class) {
            'swap_used' => $this->swapUsed,
            'swap_free' => $this->swapFree,
            'swap_used_disk' => $this->swapUsedOnDisk(),
            'zswap' => $this->zswap,
            default => -1,
        };

        return $this->swapTotal > 0 && $value >= 0 ? round($value * 100.0 / $this->swapTotal) : Sentinel::UNMEASURED;
    }
}
