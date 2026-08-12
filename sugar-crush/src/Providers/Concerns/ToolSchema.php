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
