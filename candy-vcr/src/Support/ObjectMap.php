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
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     * @throws \InvalidArgumentException If any key is not a string.
     */
    public static function of(array $data, string $where): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException("candy-vcr: {$where} must be a string-keyed object, integer key {$key} given");
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
