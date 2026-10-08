<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Read;

/**
 * Enumerates PCI accelerators from sysfs. Each list is de-duplicated by
 * PCI slot and ordered by the class entry's natural order (card0, card1,
 * …), so device order is deterministic across hosts.
 */
final class PciCards
{
    private function __construct()
    {
    }

    /**
     * Every `/sys/class/drm/cardN` (digits only — skips card1-DP-1,
     * renderD128; btop Asysfs::is_card_node) with a readable device.
     *
     * @return list<PciCard>
     */
    public static function drm(Paths $paths): array
    {
        $dir = $paths->sys('class/drm');
        $cards = [];
        foreach (Read::entries($dir) as $entry) {
            if (preg_match('/^card\d+$/', $entry) === 1) {
                $cards[] = PciCard::at($entry, "$dir/$entry/device");
            }
        }

        return self::unique($cards);
    }

    /**
     * Every `/sys/class/accel/accelN` (the compute-accelerator class NPUs
     * register in) plus every device bound to `$drivers` under
     * /sys/bus/pci/drivers (#985 reads intel_vpu there).
     *
     * @param list<string> $drivers
     * @return list<PciCard>
     */
    public static function accel(Paths $paths, array $drivers = []): array
    {
        $cards = [];
        $dir = $paths->sys('class/accel');
        foreach (Read::entries($dir) as $entry) {
            if (preg_match('/^accel\d+$/', $entry) === 1) {
                $cards[] = PciCard::at($entry, "$dir/$entry/device");
            }
        }
        foreach ($drivers as $driver) {
            $bound = $paths->sys("bus/pci/drivers/$driver");
            foreach (Read::entries($bound) as $entry) {
                if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/i', $entry) === 1) {
                    $cards[] = PciCard::at($entry, "$bound/$entry");
                }
            }
        }

        return self::unique($cards);
    }

    /**
     * @param list<PciCard|null> $cards
     * @return list<PciCard>
     */
    private static function unique(array $cards): array
    {
        $out = [];
        $seen = [];
        foreach ($cards as $card) {
            if ($card === null) {
                continue;
            }
            $key = $card->busId === 'n/a' ? $card->device : $card->busId;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $card;
            }
        }

        return $out;
    }
}
