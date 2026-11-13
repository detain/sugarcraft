<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

/**
 * Represents a date range with start and end dates.
 *
 * Membership is decided on the calendar day (Y-m-d), never the absolute
 * instant: a start, end, or probe carrying a different time zone compares
 * equal when it shows the same wall date, matching how the picker renders
 * whole days.
 */
final readonly class DateRange
{
    public function __construct(
        public ?\DateTimeImmutable $start = null,
        public ?\DateTimeImmutable $end = null,
    ) {}

    /**
     * House factory mirroring the constructor.
     *
     * AGENTS.md doctrine says factories are the advertised entry point; the
     * public ctor stays legal as the declared value-object exception (see
     * CALIBER_LEARNINGS) pending a v2 flip to a private ctor.
     */
    public static function new(?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): self
    {
        return new self($start, $end);
    }

    public function withStart(\DateTimeImmutable $start): self
    {
        return new self($start, $this->end);
    }

    public function withEnd(\DateTimeImmutable $end): self
    {
        return new self($this->start, $end);
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        if ($this->start === null) {
            return false;
        }
        $d = self::dayKey($date);
        if ($d < self::dayKey($this->start)) {
            return false;
        }
        if ($this->end !== null && $d > self::dayKey($this->end)) {
            return false;
        }

        return true;
    }

    /**
     * Whole elapsed days between start and end — 2026-01-01 to 2026-01-03
     * returns 2, although the calendar span covers three days. The count is
     * SIGNED: an inverted range (end before start) reports the negative
     * elapsed figure — 2026-01-05 to 2026-01-01 returns -4, never a mirror
     * +4 — so a consumer can tell "backwards" from "same instant".
     * Membership deliberately disagrees: contains() treats an inverted
     * range as empty (false everywhere); the sign is direction information,
     * not an invitation to probe inside. With an open-ended range, $now
     * supplies the closing date; it defaults to real wall-clock now so the
     * pure-integer math stays testable without a clock injection when the
     * caller wants the live figure.
     */
    public function durationInDays(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->start === null) {
            return null;
        }
        $end = $this->end ?? $now ?? new \DateTimeImmutable();
        // Midnight-normalised operands keep the diff an integer day count;
        // DateInterval::days is unsigned, so the direction is applied here.
        $days = $end->setTime(0, 0, 0)->diff($this->start->setTime(0, 0, 0))->days;

        return self::dayKey($end) < self::dayKey($this->start) ? -$days : $days;
    }

    public function isComplete(): bool
    {
        return $this->start !== null && $this->end !== null;
    }

    private static function dayKey(\DateTimeImmutable $date): int
    {
        return (int) $date->format('Ymd');
    }
}
