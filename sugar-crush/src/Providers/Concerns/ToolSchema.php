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
 *
 * AND IT IS NOT ONLY THE ROOT. This correction was a single flat `if` on the
 * top-level `properties` key for as long as every tool on the wire was a
 * built-in with a hand-written schema. {@see \SugarCraft\Crush\Tools\McpToolBridge}
 * is the first thing that puts THIRD-PARTY JSON Schema on the wire, and a nested
 * no-argument object is a routine MCP shape:
 *
 *     server schema: {"type":"object","properties":{"opts":{"type":"object","properties":[],"required":[]}},"required":[]}
 *     ON THE WIRE:   …"properties":{"opts":{"type":"object","properties":[]…
 *
 * — which 400s the whole request exactly as the root case did, for the same
 * reason, with the same total blast radius. So the walk below is recursive.
 */
trait ToolSchema
{
    /**
     * Keywords whose value is a MAP of name => sub-schema.
     *
     * The three sets below are named explicitly rather than the walk just
     * recursing into every array, because JSON Schema also carries INSTANCE
     * VALUES — `enum`, `const`, `default`, `examples` — which are ordinary data
     * belonging to the tool's own domain. `"enum": []` is a legitimate empty JSON
     * array, and a `default` that happens to contain a key called `properties` is
     * a default object, not a schema. Rewriting either would corrupt the tool's
     * contract to fix a problem it does not have.
     */
    private const SCHEMA_MAP_KEYS = [
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
    ];

    /** Keywords whose value is a LIST of sub-schemas. */
    private const SCHEMA_LIST_KEYS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /**
     * Keywords whose value is ONE sub-schema — except `items`, which is one
     * schema in every modern draft and a TUPLE of schemas in draft-04, and
     * `additionalProperties`/`unevaluatedProperties`, which are legitimately a
     * boolean. {@see normalizeSchemaNode()} tells the array shapes apart with
     * `array_is_list()` and leaves a boolean exactly as it found it.
     */
    private const SCHEMA_SINGLE_KEYS = [
        'items',
        'contains',
        'additionalProperties',
        'unevaluatedProperties',
        'propertyNames',
        'not',
        'if',
        'then',
        'else',
    ];

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeToolSchema(array $schema): array
    {
        return self::normalizeSchemaNode($schema);
    }

    /**
     * One schema node, corrected and then descended into.
     *
     * Only `properties` is REWRITTEN: `required` IS a JSON array, so an empty list
     * is already the right encoding for it, and so is every keyword in
     * {@see SCHEMA_LIST_KEYS}.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function normalizeSchemaNode(array $node): array
    {
        if (($node['properties'] ?? null) === []) {
            $node['properties'] = new \stdClass();
        }

        // Only keys the server actually sent are touched: an absent keyword must
        // stay absent rather than being invented as `null`.
        foreach ([...self::SCHEMA_MAP_KEYS, ...self::SCHEMA_LIST_KEYS] as $key) {
            if (is_array($node[$key] ?? null)) {
                $node[$key] = self::normalizeEachSchema($node[$key]);
            }
        }

        foreach (self::SCHEMA_SINGLE_KEYS as $key) {
            $child = $node[$key] ?? null;
            if (!is_array($child)) {
                continue;
            }

            // A LIST here is draft-04's tuple `items`, i.e. several schemas; a
            // map is the one-schema form. `[]` satisfies both readings and is
            // left alone by both.
            $node[$key] = array_is_list($child)
                ? self::normalizeEachSchema($child)
                : self::normalizeSchemaNode($child);
        }

        return $node;
    }

    /**
     * Normalise every sub-schema in a map or list of schemas.
     *
     * @param array<mixed> $container
     * @return array<mixed>
     */
    private static function normalizeEachSchema(array $container): array
    {
        foreach ($container as $index => $child) {
            if (is_array($child)) {
                $container[$index] = self::normalizeSchemaNode($child);
            }
        }

        return $container;
    }

    /**
     * Render an assistant turn's tool calls into the OpenAI wire shape.
     *
     * {@see \SugarCraft\Crush\Tools\ToolCall} keeps its state in PRIVATE
     * properties behind bare accessors, and `json_encode()` serializes only
     * public ones — so handing the objects straight to the request body
     * emitted `"tool_calls": [{}, {}, {}]`. SGLang answered with one
     * `'msg': 'Field required'` per call for the missing `function` key and
     * 400'd the request, which meant any turn that actually CALLED a tool
     * died on the follow-up request that reports the results back.
     *
     * `function.arguments` is a JSON STRING in the OpenAI schema, not an
     * object, and an argument-less call hits the same empty-array-vs-object
     * trap as {@see normalizeToolSchema()} — hence JSON_FORCE_OBJECT.
     *
     * Already-shaped arrays (a decoded transcript replayed from disk, say)
     * are passed through untouched.
     *
     * @param  array<mixed> $toolCalls
     * @return array<mixed>
     */
    private function formatToolCalls(array $toolCalls): array
    {
        return array_map(static function (mixed $call): mixed {
            if (!$call instanceof \SugarCraft\Crush\Tools\ToolCall) {
                return $call;
            }

            return [
                'id' => $call->id(),
                'type' => 'function',
                'function' => [
                    'name' => $call->name(),
                    'arguments' => json_encode($call->arguments(), JSON_FORCE_OBJECT) ?: '{}',
                ],
            ];
        }, $toolCalls);
    }
}
