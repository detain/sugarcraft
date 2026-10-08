<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Vms;

use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Config\Schema;

/**
 * The VM dashboard's card orders ({@see Schema::VMS_SORTING}): busiest
 * first for cpu / mem / disk / net / psi, A-Z for name; ties (and every
 * unmeasured reading, which counts as 0) fall back to the name, then the
 * scope path, so the grid never shuffles cards that read the same.
 */
final class VmsSort
{
    private function __construct()
    {
    }

    /**
     * @param list<VmGuest> $guests
     * @return list<VmGuest>
     */
    public static function sorted(array $guests, string $mode): array
    {
        $key = match ($mode) {
            'mem' => static fn (VmGuest $g): float => (float) $g->memPercent(),
            'disk' => static fn (VmGuest $g): float => max(0.0, $g->diskRead) + max(0.0, $g->diskWrite),
            'net' => static fn (VmGuest $g): float => max(0.0, $g->netRx) + max(0.0, $g->netTx),
            'psi' => static fn (VmGuest $g): float => max(0.0, $g->pressure()[1] ?? 0.0),
            'name' => null,
            default => static fn (VmGuest $g): float => max(0.0, $g->cpu),
        };
        usort($guests, static function (VmGuest $a, VmGuest $b) use ($key): int {
            if ($key !== null) {
                $by = $key($b) <=> $key($a);
                if ($by !== 0) {
                    return $by;
                }
            }

            return [$a->name, $a->path] <=> [$b->name, $b->path];
        });

        return $guests;
    }

    /** The next (`$step` 1) or previous (-1) order, wrapping; an unknown value starts over at cpu. */
    public static function cycled(string $mode, int $step): string
    {
        $modes = Schema::VMS_SORTING;
        $at = array_search($mode, $modes, true);
        if ($at === false) {
            return $modes[0];
        }
        $n = \count($modes);

        return $modes[(($at + $step) % $n + $n) % $n];
    }
}
