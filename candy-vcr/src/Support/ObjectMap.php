<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Support;

/**
 * Boundary parser for decoded JSON/YAML mappings.
 *
 * `Event::$payload` (and everything downstream — serializers, hooks,
 * assertions) is contractually a string-keyed map. A decoded document is
 * only known to be `array<array-key,mixed>`, and PHP's assoc decode
 * silently turns the JSON key `"0"` into an integer key. `of()` parses
 * that gap once at load: legitimate object payloads pass through
 * untouched; integer-keyed payloads fail loud instead of corrupting
 * every later consumer.
 */
final class ObjectMap
{
    /**
     * Ceiling on how deeply a decoded value may nest (finding 109).
     *
     * JSON tolerates 512 levels by default; no honest cassette payload
     * (msg envelopes, OSC params, user keys) nests past a handful, and a
     * hostile deep document would detonate inside later consumers —
     * serializers, hooks, assertions — far from the parse. A documented
     * SMALL cap rejects it at the load boundary instead. Deliberately not
     * configurable: the point is a hard bound, not a knob to lose.
     */
    public const MAX_NESTING = 64;

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     * @throws \InvalidArgumentException If any key is not a string, or a
     *                                   value nests deeper than MAX_NESTING.
     */
    public static function of(array $data, string $where): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException("{$where} must be a string-keyed object, integer key {$key} given");
            }
            self::guardNesting($value, $where);
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Breadth-first depth walk (finding 109): one stack of LEVELS, so every
     * node is visited exactly once and the guard itself can never stack
     * overflow on the deep document it exists to reject.
     */
    private static function guardNesting(mixed $value, string $where): void
    {
        if (!is_array($value)) {
            return;
        }

        $levels = [$value];
        $depth = 1;
        while ($levels !== []) {
            if ($depth > self::MAX_NESTING) {
                throw new \InvalidArgumentException(
                    "{$where} nests deeper than " . self::MAX_NESTING . ' levels'
                );
            }
            $next = [];
            foreach ($levels as $node) {
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $next[] = $child;
                    }
                }
            }
            $levels = $next;
            $depth++;
        }
    }
}
