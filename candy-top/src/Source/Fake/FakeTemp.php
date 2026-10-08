<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\Sensor;
use SugarCraft\Top\Collect\TempSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see TempSnapshot}s shaped like a coretemp chip: a
 * `Package id 0` cpu sensor plus one `Core N` sensor per physical core
 * (`$sensors`, typically half the logical cpus), crit 100 °C.
 */
final class FakeTemp implements Source
{
    private function __construct(
        private readonly int $sensors,
        private readonly int $step,
    ) {
    }

    public static function new(int $sensors = 4): self
    {
        return new self(max(0, $sensors), 0);
    }

    public function sample(): array
    {
        $package = 'coretemp/Package id 0';
        $all = [$package => new Sensor($package, round(45.0 + Wave::percent($this->step, 0.4) * 0.3), 80.0, 100.0)];
        $cores = [];
        for ($i = 0; $i < $this->sensors; $i++) {
            $name = 'coretemp/Core ' . $i;
            $temp = round(40.0 + Wave::percent($this->step, $i * 1.1) * 0.35);
            $all[$name] = new Sensor($name, $temp, 80.0, 100.0);
            $cores[] = $temp;
        }

        return [new TempSnapshot($all, $package, $cores), new self($this->sensors, $this->step + 1)];
    }
}
