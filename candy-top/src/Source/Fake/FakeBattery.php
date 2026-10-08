<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\BatterySnapshot;
use SugarCraft\Top\Source\Source;

/**
 * A deterministic discharging laptop battery `BAT0` for the cpu-box badge:
 * starts at 87 % and loses one point every three samples (floor 5 %), two
 * minutes left per percent, ~12.5 W draw. `selected_battery` naming any
 * other supply falls back to BAT0, like the live auto-select does.
 */
final class FakeBattery implements Source
{
    private function __construct(
        private readonly int $step,
    ) {
    }

    public static function new(): self
    {
        return new self(0);
    }

    /** Accepted for parity with {@see \SugarCraft\Top\Collect\Battery::withSelected()}; there is only BAT0. */
    public function withSelected(?string $battery): self
    {
        return $this;
    }

    public function sample(): array
    {
        $percent = max(5, 87 - intdiv($this->step, 3));
        $watts = round(12.5 + (Wave::percent($this->step, 2.2) - 50.0) / 25.0, 2);

        return [new BatterySnapshot('BAT0', $percent, 'discharging', $percent * 120, $watts), new self($this->step + 1)];
    }
}
