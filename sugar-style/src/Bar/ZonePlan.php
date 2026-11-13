<?php

declare(strict_types=1);

namespace SugarCraft\Style\Bar;

/**
 * What a {@see ZoneRow} decided for one width: the entries painted, the
 * entries collapsed, and the overflow entry standing in for them (null when
 * nothing was dropped or no marker fits).
 */
final class ZonePlan
{
    /**
     * @param list<ZoneEntry> $placed
     * @param list<ZoneEntry> $dropped
     */
    public function __construct(
        public readonly array $placed,
        public readonly array $dropped,
        public readonly ?ZoneEntry $overflow,
    ) {}

    /** @return list<ZoneEntry> */
    public function placed(): array
    {
        return $this->placed;
    }

    /** @return list<ZoneEntry> */
    public function dropped(): array
    {
        return $this->dropped;
    }

    public function overflow(): ?ZoneEntry
    {
        return $this->overflow;
    }
}
