<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * One row of `ipmitool sensor list`:
 * `name | value | unit | status | lnr | lcr | lnc | unc | ucr | unr`.
 * `value` is null when the BMC has no reading (`na`, a disabled or absent
 * device); a discrete sensor keeps its state bitmask in {@see $state}
 * (`sensor list`) or its decoded state in {@see $text} (`sdr elist`:
 * "Fully Redundant", "Device Absent").
 * Thresholds are null when the BMC does not define them (`na`).
 */
final class IpmiSensor
{
    public function __construct(
        public readonly string $name,
        public readonly ?float $value,
        public readonly string $unit,
        public readonly SensorKind $kind,
        public readonly SensorStatus $status,
        public readonly ?float $lnr = null,
        public readonly ?float $lcr = null,
        public readonly ?float $lnc = null,
        public readonly ?float $unc = null,
        public readonly ?float $ucr = null,
        public readonly ?float $unr = null,
        public readonly ?int $state = null,
        public readonly string $text = '',
    ) {
    }

    /** A copy with another value and status (fake data, tests). */
    public function withValue(?float $value, ?SensorStatus $status = null): self
    {
        return new self(
            $this->name, $value, $this->unit, $this->kind, $status ?? $this->status,
            $this->lnr, $this->lcr, $this->lnc, $this->unc, $this->ucr, $this->unr, $this->state, $this->text,
        );
    }

    /** A copy carrying `$from`'s six thresholds (values come from `sdr elist`, thresholds from one `sensor list`). */
    public function withThresholds(self $from): self
    {
        return new self(
            $this->name, $this->value, $this->unit, $this->kind, $this->status,
            $from->lnr, $from->lcr, $from->lnc, $from->unc, $from->ucr, $from->unr, $this->state, $this->text,
        );
    }

    /** True when any threshold is defined. */
    public function hasThresholds(): bool
    {
        return $this->lnr !== null || $this->lcr !== null || $this->lnc !== null
            || $this->unc !== null || $this->ucr !== null || $this->unr !== null;
    }

    /** True when there is a number to show. */
    public function reads(): bool
    {
        return $this->value !== null && $this->kind !== SensorKind::Discrete;
    }

    /** The upper limit a meter fills toward: critical, else non-critical, else non-recoverable. */
    public function upper(): ?float
    {
        return $this->ucr ?? $this->unc ?? $this->unr;
    }

    /** The lower limit: critical, else non-critical, else non-recoverable. */
    public function lower(): ?float
    {
        return $this->lcr ?? $this->lnc ?? $this->lnr;
    }

    /**
     * Status from the thresholds when the BMC's own word is missing or
     * only `ok`: a reading past a threshold is at least that severe. The
     * BMC's word wins when it is louder (it may apply hysteresis).
     */
    public function severity(): SensorStatus
    {
        $own = $this->status;
        $v = $this->value;
        if ($v === null) {
            return $own;
        }
        $derived = match (true) {
            ($this->unr !== null && $v >= $this->unr) || ($this->lnr !== null && $v <= $this->lnr) => SensorStatus::NonRecoverable,
            ($this->ucr !== null && $v >= $this->ucr) || ($this->lcr !== null && $v <= $this->lcr) => SensorStatus::Critical,
            ($this->unc !== null && $v >= $this->unc) || ($this->lnc !== null && $v <= $this->lnc) => SensorStatus::NonCritical,
            default => SensorStatus::Ok,
        };

        return SensorStatus::worst($own === SensorStatus::Unknown ? SensorStatus::Ok : $own, $derived);
    }
}
