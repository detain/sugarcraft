<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * `ipmitool sel info` (+ the newest entry from `sel list last 1`): how
 * full the BMC's System Event Log is — a full SEL drops new events
 * silently on most BMCs, which is worth a badge — and what it last said.
 */
final class IpmiSel
{
    public function __construct(
        public readonly int $entries,
        public readonly ?int $percentUsed,
        public readonly bool $overflow = false,
        public readonly string $lastAdd = '',
        public readonly string $latest = '',
    ) {
    }

    public function withLatest(string $latest): self
    {
        return new self($this->entries, $this->percentUsed, $this->overflow, $this->lastAdd, $latest);
    }

    /** What identifies "a new entry arrived" without reading the log. */
    public function key(): string
    {
        return $this->entries . '|' . $this->lastAdd;
    }
}
