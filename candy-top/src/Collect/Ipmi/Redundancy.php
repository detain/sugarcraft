<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * The state of a discrete redundancy sensor (IPMI v2.0 generic reading type
 * 0x0B), e.g. HP's `Fans` aggregate: `sensor list` prints only its bitmask
 * (`0x1` = offset 0 = Fully Redundant), so the offsets are decoded here.
 *
 * Spec offsets (bit n of the mask): 0 Fully Redundant, 1 Redundancy Lost,
 * 2 Redundancy Degraded, 3 Non-redundant Sufficient from Redundant,
 * 4 Non-redundant Sufficient from Insufficient, 5 Non-redundant Insufficient
 * Resources, 6 Degraded from Fully Redundant, 7 Degraded from Non-redundant.
 * When several offsets are asserted the WORST severity wins (a BMC that
 * reports Full alongside Lost must not read healthy); ties go to the lowest
 * offset. Bits above 0x80 are outside the spec and ignored, never thrown on:
 * BMC data is untrusted. Column decision: discrete rows carry the event
 * bitmask in the reading column; `sdr elist` text, when present, wins.
 */
enum Redundancy
{
    case Full;
    case Lost;
    case Degraded;
    case NonRedundantSufficient;
    case NonRedundantInsufficient;

    /** @var list<self> indexed by IPMI offset 0..7 */
    private const OFFSETS = [
        self::Full,
        self::Lost,
        self::Degraded,
        self::NonRedundantSufficient,
        self::NonRedundantSufficient,
        self::NonRedundantInsufficient,
        self::Degraded,
        self::Degraded,
    ];

    public static function fromState(?int $state): ?self
    {
        if ($state === null) {
            return null;
        }

        $best = null;
        foreach (self::OFFSETS as $bit => $candidate) {
            if (($state & (1 << $bit)) === 0) {
                continue;
            }
            if ($best === null || $candidate->rank() > $best->rank()) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function rank(): int
    {
        return match ($this->severity()) {
            SensorStatus::Ok => 0,
            SensorStatus::NonCritical => 1,
            default => 2,
        };
    }

    /** Decode `sdr elist`'s text form ("Fully Redundant", "Redundancy Lost", ...). */
    public static function fromText(string $text): ?self
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'redundancy lost') => self::Lost,
            // Before Full: "Degraded from Fully Redundant" contains both phrases.
            str_contains($t, 'degraded') => self::Degraded,
            str_contains($t, 'fully redundant') => self::Full,
            str_contains($t, 'insufficient resources') && !str_contains($t, 'from insufficient') => self::NonRedundantInsufficient,
            str_contains($t, 'non-redundant') || str_contains($t, 'sufficient') => self::NonRedundantSufficient,
            default => null,
        };
    }

    /** Lang key under `ipmi.redundancy.*`. */
    public function key(): string
    {
        return match ($this) {
            self::Full => 'ipmi.redundancy.full',
            self::Lost => 'ipmi.redundancy.lost',
            self::Degraded => 'ipmi.redundancy.degraded',
            self::NonRedundantSufficient => 'ipmi.redundancy.sufficient',
            self::NonRedundantInsufficient => 'ipmi.redundancy.insufficient',
        };
    }

    public function severity(): SensorStatus
    {
        return match ($this) {
            self::Full => SensorStatus::Ok,
            self::Degraded, self::NonRedundantSufficient => SensorStatus::NonCritical,
            self::Lost, self::NonRedundantInsufficient => SensorStatus::Critical,
        };
    }
}
