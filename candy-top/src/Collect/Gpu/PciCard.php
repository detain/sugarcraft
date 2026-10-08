<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Read;
use SugarCraft\Top\Collect\Sentinel;

/**
 * One PCI accelerator as sysfs shows it: a `/sys/class/drm/cardN`,
 * `/sys/class/accel/accelN` or `/sys/bus/pci/drivers/<drv>/<slot>` entry
 * resolved to its PCI device directory.
 *
 * Driver and slot come from the device's `uevent` (DRIVER=,
 * PCI_SLOT_NAME=), falling back to the `driver` symlink and the device
 * directory's own name — uevent first because it is a plain file a
 * fixture tree can carry, where btop Asysfs::init reads the symlink.
 */
final class PciCard
{
    public function __construct(
        public readonly string $name,
        public readonly string $device,
        public readonly int $vendorId,
        public readonly int $deviceId,
        public readonly int $revision,
        public readonly string $driver,
        public readonly string $busId,
    ) {
    }

    /**
     * @param string $name   the class entry ("card0", "accel0", or the slot)
     * @param string $device the PCI device directory
     */
    public static function at(string $name, string $device): ?self
    {
        $vendor = self::hex(Read::line("$device/vendor"));
        if ($vendor === null) {
            return null;
        }
        $uevent = [];
        foreach (explode("\n", Read::file("$device/uevent") ?? '') as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $uevent[$k] = trim($v);
            }
        }
        $driver = $uevent['DRIVER'] ?? '';
        if ($driver === '') {
            $link = @readlink("$device/driver");
            $driver = $link === false ? Sentinel::UNAVAILABLE : basename($link);
        }
        $slot = $uevent['PCI_SLOT_NAME'] ?? '';
        if ($slot === '') {
            $real = realpath($device);
            $base = basename($real === false ? $device : $real);
            $slot = preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/i', $base) === 1 ? $base : Sentinel::UNAVAILABLE;
        }

        return new self(
            $name,
            $device,
            $vendor,
            self::hex(Read::line("$device/device")) ?? 0,
            self::hex(Read::line("$device/revision")) ?? 0,
            $driver,
            strtolower($slot),
        );
    }

    /**
     * PCIe generation of the current link, from `current_link_speed`
     * ("16.0 GT/s PCIe"); UNMEASURED_INT when absent (an iGPU) or unknown.
     */
    public function pcieGen(): int
    {
        $speed = Read::line("{$this->device}/current_link_speed");
        if ($speed === null || preg_match('/^([\d.]+)\s*GT\/s/', $speed, $m) !== 1) {
            return Sentinel::UNMEASURED_INT;
        }

        return match ((string) (float) $m[1]) {
            '2.5' => 1,
            '5' => 2,
            '8' => 3,
            '16' => 4,
            '32' => 5,
            '64' => 6,
            default => Sentinel::UNMEASURED_INT,
        };
    }

    /** Lanes of the current link (`current_link_width`); UNMEASURED_INT when absent. */
    public function pcieWidth(): int
    {
        $width = Read::int("{$this->device}/current_link_width");

        return $width !== null && $width > 0 ? $width : Sentinel::UNMEASURED_INT;
    }

    /** The first `hwmon*` directory under the device (btop Asysfs::find_hwmon), or null. */
    public function hwmon(): ?string
    {
        foreach (Read::entries("{$this->device}/hwmon") as $entry) {
            if (preg_match('/^hwmon\d+$/', $entry) === 1 && is_dir("{$this->device}/hwmon/$entry")) {
                return "{$this->device}/hwmon/$entry";
            }
        }

        return null;
    }

    /** "0x1002\n" → 0x1002; null when unreadable. */
    private static function hex(?string $v): ?int
    {
        return $v !== null && preg_match('/^(0x)?([0-9a-f]+)$/i', $v, $m) === 1 ? (int) hexdec($m[2]) : null;
    }
}
