<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One mounted filesystem with its space figures in bytes. Mounts whose
 * statvfs fails never become a Mount (Mounts ignores them, like btop), so
 * the space fields are always measured.
 */
final class Mount
{
    public function __construct(
        public readonly string $device,
        public readonly string $mountpoint,
        public readonly string $fstype,
        public readonly string $name,
        public readonly int $total,
        public readonly int $free,
        public readonly int $used,
    ) {
    }

    /** btop rounding: used% rounded; a zero-size filesystem reads 0/0. */
    public function usedPercent(): float
    {
        return $this->total <= 0 ? 0.0 : round($this->used * 100.0 / $this->total);
    }

    /** btop derives free% as the complement of the rounded used%. */
    public function freePercent(): float
    {
        return $this->total <= 0 ? 0.0 : 100.0 - $this->usedPercent();
    }
}
