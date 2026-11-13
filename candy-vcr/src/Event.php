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
 * - Input:  `msg` (envelope written by a MsgSerializer)
 * - Quit:   (empty)
 *
 * Mirrors charmbracelet/x/vcr Event.
 */
final readonly class Event
{
    /**
     * Trust ceiling for a cassette timestamp: 24 h of virtual tape. Nothing
     * legitimate approaches it, while a corrupted `{"t":1e17}` must never
     * stream a render (findings 001/002 — the door half; the loop-cap half
     * lives in FrameStream).
     */
    public const MAX_TIMESTAMP_SECONDS = 86_400.0;

    public function __construct(
        public float $t,
        public EventKind $kind,
        /** @var array<string, mixed> string-keyed map, keys determined by kind. */
        public array $payload,
    ) {
        // Non-finite first: NAN fails every comparison, so a finite check
        // after the range guards would let it through.
        if (!is_finite($t)) {
            throw new \InvalidArgumentException("Event time must be a finite number of seconds, got {$t}");
        }
        if ($t < 0.0) {
            throw new \InvalidArgumentException("Event time must be non-negative, got {$t}");
        }
        if ($t > self::MAX_TIMESTAMP_SECONDS) {
            $cap = self::MAX_TIMESTAMP_SECONDS;
            throw new \InvalidArgumentException("Event time must not exceed the 24h cassette ceiling ({$cap}s), got {$t}");
        }
    }
}
