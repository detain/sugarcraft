<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

/**
 * Represents a date range with start and end dates.
 */
final readonly class DateRange
{
    public function __construct(
        public ?\DateTimeImmutable $start = null,
        public ?\DateTimeImmutable $end = null,
    ) {}

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
        $d = $date->setTime(0, 0, 0);
        $s = $this->start->setTime(0, 0, 0);
        if ($d < $s) {
            return false;
        }
        if ($this->end !== null) {
            $e = $this->end->setTime(0, 0, 0);
            if ($d > $e) {
                return false;
            }
        }
        return true;
    }

    public function durationInDays(): ?int
    {
        if ($this->start === null) {
            return null;
        }
        $s = $this->start->setTime(0, 0, 0);
        $e = ($this->end ?? new \DateTimeImmutable())->setTime(0, 0, 0);
        // Both endpoints are midnight; diff->days is always an integer day count.
        return $e->diff($s)->days;
    }

    public function isComplete(): bool
    {
        return $this->start !== null && $this->end !== null;
    }
}
