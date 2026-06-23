<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Subtitle;

/**
 * A single subtitle cue — the text shown between $start and $end (seconds).
 * Immutable.
 */
final class Cue
{
    public function __construct(
        public readonly float $start,
        public readonly float $end,
        public readonly string $text,
    ) {
    }

    /** Whether $seconds falls within [start, end) — i.e. this cue is showing. */
    public function contains(float $seconds): bool
    {
        return $seconds >= $this->start && $seconds < $this->end;
    }
}
