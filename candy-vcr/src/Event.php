<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * One row of a cassette: a kind, a payload, and a timestamp in seconds since
 * cassette start.
 *
 * `payload` is an opaque associative map whose keys depend on `kind`:
 * - Resize: `cols`, `rows`
 * - Output: `b` (string, may contain raw bytes — JSONL serializer handles encoding)
 * - Input:  `msg` (envelope written by a MsgSerializer; PR3)
 * - Quit:   (empty)
 *
 * Mirrors charmbracelet/x/vcr Event.
 */
final readonly class Event
{
    public function __construct(
        public float $t,
        public EventKind $kind,
        public array $payload,
    ) {
        if ($t < 0.0) {
            throw new \InvalidArgumentException("Event time must be non-negative, got {$t}");
        }
    }
}
