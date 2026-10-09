<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * One round of the ipmi collector. Rounds are partial on purpose — the
 * cheap power reading comes every round, the multi-second SDR walk
 * (`sensor list`), chassis status and SEL only on the slow cadence, the
 * static header once — so every part is nullable and null means "not
 * read this round, keep what you had" (the panel merges, #1008-style),
 * never "gone".
 *
 * `thresholdsNext` says the next round runs the one-off threshold walk
 * (`sensor list`, 4-29 s), during which no power reading lands — the box
 * says so instead of looking frozen.
 *
 * `state` is the collector's availability; when it is a failure the
 * panel shows {@see IpmiState::key()} with `device` filled in.
 */
final class IpmiSnapshot
{
    /**
     * @param ?list<IpmiSensor> $sensors
     */
    public function __construct(
        public readonly IpmiState $state,
        public readonly ?IpmiInfo $info = null,
        public readonly ?IpmiPower $power = null,
        public readonly ?array $sensors = null,
        public readonly ?IpmiChassis $chassis = null,
        public readonly ?IpmiSel $sel = null,
        public readonly string $device = '',
        public readonly int $round = 0,
        public readonly bool $thresholdsNext = false,
    ) {
    }

    public static function starting(): self
    {
        return new self(IpmiState::Starting);
    }

    /** A failure with the device path the reason names. */
    public static function failed(IpmiState $state, string $device = '', int $round = 0): self
    {
        return new self($state, null, null, null, null, null, $device, $round);
    }
}
