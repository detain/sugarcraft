<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

final class TempSnapshot
{
    /**
     * @param array<string, Sensor> $sensors keyed by "chip/label", discovery order
     * @param list<float>           $cores per-core temps in btop's sorted core-sensor order (empty = cpu_temp_only)
     */
    public function __construct(
        public readonly array $sensors,
        public readonly ?string $cpuSensor,
        public readonly array $cores,
    ) {
    }

    /** Package/die temperature in °C, UNMEASURED without a cpu sensor. */
    public function cpu(): float
    {
        return $this->cpuSensor === null ? Sentinel::UNMEASURED : ($this->sensors[$this->cpuSensor]->temp ?? Sentinel::UNMEASURED);
    }

    public function cpuCrit(): float
    {
        return $this->cpuSensor === null ? Sentinel::UNMEASURED : ($this->sensors[$this->cpuSensor]->crit ?? Sentinel::UNMEASURED);
    }
}
