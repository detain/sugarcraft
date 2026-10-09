<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * One power supply assembled from the sensors that name it: AMI's
 * `PSU1 Power In` / `PSU1 Power Out`, HP's `Power Supply 1` /
 * `PS 1 Output` / `PS 1 Presence`. Either side may be missing.
 */
final class IpmiPsu
{
    public function __construct(
        public readonly int $index,
        public readonly ?IpmiSensor $in = null,
        public readonly ?IpmiSensor $out = null,
        public readonly ?bool $present = null,
    ) {
    }

    public function withIn(IpmiSensor $in): self
    {
        return new self($this->index, $in, $this->out, $this->present);
    }

    public function withOut(IpmiSensor $out): self
    {
        return new self($this->index, $this->in, $out, $this->present);
    }

    public function withPresent(bool $present): self
    {
        return new self($this->index, $this->in, $this->out, $present);
    }

    public function inWatts(): ?float
    {
        return $this->in?->value;
    }

    public function outWatts(): ?float
    {
        return $this->out?->value;
    }

    /** Output over input, only when both are measured and out < in (HP reports the same figure twice). */
    public function efficiency(): ?float
    {
        $in = $this->inWatts();
        $out = $this->outWatts();

        return $in !== null && $out !== null && $in > 0 && $out > 0 && $out < $in ? $out / $in : null;
    }

    /** True when the PSU reads watts or a presence sensor says it is there. */
    public function live(): bool
    {
        return $this->inWatts() !== null || $this->outWatts() !== null || $this->present === true;
    }

    /** The rated output: the out sensor's upper threshold (critical first), when the BMC defines it. */
    public function capacity(): ?float
    {
        $cap = $this->out?->ucr ?? $this->out?->unc ?? $this->in?->ucr ?? $this->in?->unc;

        return $cap !== null && $cap > 0 ? $cap : null;
    }

    /** The worst threshold state of its two sides. */
    public function severity(): SensorStatus
    {
        $s = SensorStatus::Ok;
        foreach ([$this->in, $this->out] as $side) {
            if ($side !== null && $side->value !== null) {
                $s = SensorStatus::worst($s, $side->severity());
            }
        }

        return $s;
    }
}
