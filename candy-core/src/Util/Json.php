<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Guarded JSON helpers for the SugarCraft monorepo.
 *
 * Consolidates the unguarded `json_decode($s, true)` + `(array)` cast sites
 * duplicated across durable-state consumers. A bare cast silently turns a
 * scalar top level (`"5"`, `"true"`, `"null"`) into a surprising array shape;
 * decodeArray() instead fails loudly so a corrupt/hostile state file cannot
 * masquerade as valid `[]`-shaped data.
 */
final class Json
{
    /**
     * Static-only utility — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Decode a JSON string that MUST represent an array (JSON object or list).
     *
     * @return array<mixed>
     *
     * @throws \JsonException    On malformed JSON (JSON_THROW_ON_ERROR).
     * @throws \RuntimeException When the decoded top level is not an array.
     */
    public static function decodeArray(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'Expected JSON top level to be an array, got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }
}
