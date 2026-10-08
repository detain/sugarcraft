<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * A battery collector the cpu box's badge re-points at `selected_battery`
 * at collect time. Implemented by the Linux {@see Battery} and
 * {@see FreeBsd\Battery}.
 */
interface SelectableBattery
{
    /** null or "Auto" = auto-select. */
    public function withSelected(?string $battery): SelectableBattery;

    public function selected(): ?string;

    /** @return array{0: BatterySnapshot, 1: SelectableBattery} */
    public function sample(): array;
}
