<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The vendor tag on an accelerator device. Backed by btop's `shown_gpus`
 * tokens ("nvidia amd intel") so a vendor filter is a plain tryFrom() of
 * the config words.
 */
enum GpuVendor: string
{
    case Nvidia = 'nvidia';
    case Amd = 'amd';
    case Intel = 'intel';

    /** The PCI vendor id sysfs reports (`device/vendor`). */
    public function pciId(): int
    {
        return match ($this) {
            self::Nvidia => 0x10de,
            self::Amd => 0x1002,
            self::Intel => 0x8086,
        };
    }

    /**
     * AMD NPUs (XDNA) report the AMD CPU vendor id 0x1022, not the Radeon
     * 0x1002 — both map to Amd.
     */
    public static function fromPciId(int $id): ?self
    {
        return match ($id) {
            0x10de => self::Nvidia,
            0x1002, 0x1022 => self::Amd,
            0x8086 => self::Intel,
            default => null,
        };
    }
}
