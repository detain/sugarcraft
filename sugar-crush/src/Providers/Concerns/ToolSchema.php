<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\Concerns;

/**
 * Normalizes a {@see \SugarCraft\Crush\Tools\Tool}'s `inputSchema()` into a
 * shape that survives `json_encode()` as valid JSON Schema.
 *
 * PHP cannot distinguish an empty map from an empty list: both are `[]`, and
 * `json_encode()` renders that as the JSON array `[]`. JSON Schema requires
 * `properties` to be an OBJECT, so a parameter-less tool declaring
 * `'properties' => []` goes out on the wire as `"properties": []` and a
 * server that validates tool schemas rejects it.
 *
 * That rejection is not scoped to the offending tool — the whole
 * `chat/completions` request 400s, so a single parameter-less tool makes the
 * agent unable to send ANY message. SGLang caught exactly this:
 *
 *     Tool 6 function has invalid 'parameters' schema: [] is not of type 'object'
 *
 * Normalizing here rather than only in the tool means a future tool that
 * declares `'properties' => []` — the natural way to write "no parameters" in
 * PHP — cannot silently break every request again.
 */
trait ToolSchema
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeToolSchema(array $schema): array
    {
        // Only `properties` is corrected: `required` IS a JSON array, so an
        // empty list is already the right encoding for it.
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }
}
