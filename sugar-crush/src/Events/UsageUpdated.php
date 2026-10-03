<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Usage;

/**
 * "Step N's provider response has been billed" — handed to the `$onStep`
 * observer of {@see \SugarCraft\Crush\Backend\EngineBackend}'s turn loop the
 * moment a step's response arrives, and carried across the fork as the
 * `usage` frame (roadmap 1.C-4, Appendix O §5.1).
 *
 * Before it, a forked turn's usage crossed only on the `result` frame, so
 * nothing could show what a long turn had cost until it ended. Display only:
 * the spend cap is enforced inside the loop, never from this event, and a
 * consumer that ignores it costs the turn nothing.
 */
final readonly class UsageUpdated
{
    /**
     * @param int    $step      1-based number of the step that was billed
     * @param ?Usage $usage     that one response's usage, or null when the
     *                          provider reported none
     * @param ?Usage $turnUsage the turn's running total so far: every step's
     *                          response and every delegated run settled up
     *                          to now (null while nothing has been reported)
     */
    public function __construct(
        public int $step,
        public ?Usage $usage,
        public ?Usage $turnUsage,
    ) {
    }

    /** @return array{step:int,usage:?array<string,mixed>,turnUsage:?array<string,mixed>} the `usage` frame's fields */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'usage' => $this->usage?->toArray(),
            'turnUsage' => $this->turnUsage?->toArray(),
        ];
    }

    /** Rebuild from {@see toArray()}; null for a shape it did not write. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_int($raw['step'] ?? null)) {
            return null;
        }

        return new self($raw['step'], Usage::fromArray($raw['usage'] ?? null), Usage::fromArray($raw['turnUsage'] ?? null));
    }
}
