<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * Restores the JSON object/array distinction a `tools/call` argument map lost
 * on its way into PHP, guided by the tool's own `inputSchema`.
 *
 * WHY THIS EXISTS (audit MCP-9). A model's tool-call arguments reach PHP via
 * `json_decode($raw, true)` in every provider parser, so `{"filter":{}}` and
 * `{"filter":[]}` both arrive as `['filter' => []]`, and `json_encode()` sends
 * that back out as `"filter":[]`. The official TypeScript/Python SDK servers
 * validate arguments against the schema they advertised and refuse it
 * ("expected object, received array"), so the call fails although the model
 * sent valid JSON. MCP-1 fixed only the top level (`"arguments":{}`); this is
 * the same defect one level down, and at every level below that.
 *
 * WHY THE SCHEMA, and not "decode without assoc end to end": the arguments pass
 * through a dozen provider parsers, permission hooks that may rewrite them, and
 * a fork boundary that re-serialises them, all of which are typed `array`.
 * Every MCP transport, by contrast, already holds the tool table it was handed
 * at `tools/list`, and the `inputSchema` there says — authoritatively, because
 * it is the contract the receiving server validates against — which positions
 * are objects. Shaping at the transport fixes every embedder of this library
 * without touching anything upstream of it.
 *
 * THE RULE: only an EMPTY PHP array is ambiguous (a non-empty one encodes as an
 * object exactly when it has non-list keys, which the decode preserved). An
 * empty array becomes `\stdClass` (`{}`) when its schema admits an object and
 * does not admit an array; it stays `[]` when the schema says array, admits
 * both, or says nothing. Non-empty values are walked so that empties nested
 * inside them are judged against THEIR schema.
 *
 * WHAT "ADMITS AN OBJECT" READS:
 *  - `type`, as a string or a list (`["object","null"]` is an object position);
 *  - with no `type`, the object-only keywords (`properties`,
 *    `additionalProperties`, `patternProperties`, `required`, …) versus the
 *    array-only ones (`items`, `prefixItems`, …). Strictly JSON Schema says
 *    such a schema accepts any type, but a schema that only describes object
 *    members was written for an object, which is what the server expects;
 *  - `anyOf`/`oneOf` as a union (any branch saying nothing makes the whole
 *    position unknown — `[]` then validates against that branch), `allOf` as an
 *    intersection;
 *  - local `$ref`s (`#/$defs/…`, `#/definitions/…`) — what Pydantic, and so
 *    the Python SDK's FastMCP, emits for every nested model, usually wrapped as
 *    `anyOf: [{"$ref": …}, {"type": "null"}]` for an optional one.
 *
 * NO SCHEMA, NO GUESS: an empty array at a position the schema does not type
 * stays `[]`. That is exactly the pre-MCP-9 behaviour, so an unknown position
 * can never be made worse by this class; flipping the default would turn every
 * genuine empty list (`"tags":[]`) under a schema-less tool into an object.
 *
 * A `\stdClass` already present is the caller's statement of shape and passes
 * through untouched, contents included. A non-empty list under an object-only
 * schema is left as it is too: `["a"]` and `{"0":"a"}` decode alike, and
 * re-encoding a model's (wrong) list as an object would make an invalid call
 * silently validate as something the model never said.
 */
final class ArgumentShape
{
    /** Recursion ceiling for hostile or cyclic schemas (`$ref` loops). */
    private const MAX_DEPTH = 64;

    /**
     * Schema nodes one conform() may visit. Depth alone does not bound a
     * schema whose `anyOf` branches each `$ref` back to `#` — that is
     * branches^depth — so past this budget every further position reads as
     * "schema says nothing" and keeps its decoded shape.
     */
    private const VISIT_BUDGET = 10000;

    private int $visits = 0;

    /** Keywords that only constrain objects; their presence types an untyped schema. */
    private const OBJECT_KEYWORDS = [
        'properties', 'additionalProperties', 'patternProperties', 'required',
        'minProperties', 'maxProperties', 'propertyNames', 'dependentRequired',
        'dependentSchemas', 'unevaluatedProperties',
    ];

    /** Keywords that only constrain arrays. */
    private const ARRAY_KEYWORDS = [
        'items', 'prefixItems', 'additionalItems', 'minItems', 'maxItems',
        'uniqueItems', 'contains', 'minContains', 'maxContains', 'unevaluatedItems',
    ];

    /** @param array<array-key,mixed> $root the tool's whole inputSchema, for `$ref` lookups */
    private function __construct(private readonly array $root)
    {
    }

    /**
     * The tool-call argument map with every empty object position (per the
     * schema) turned into `\stdClass`. The top level itself stays an array —
     * an empty one is returned as `[]`, and each transport keeps its own
     * `=== [] ? new \stdClass()` for the `arguments` member (audit MCP-1), so
     * a caller typed `array` (e.g. ClaudeCodeMcpClient) can take the result.
     *
     * @param array<array-key,mixed> $arguments  decoded tool-call arguments
     * @param array<array-key,mixed> $inputSchema the tool's advertised inputSchema
     *        (`[]` when the tool is unknown: then only stdClass already present
     *        survives, and nothing is converted)
     * @return array<array-key,mixed>
     */
    public static function conform(array $arguments, array $inputSchema): array
    {
        if ($arguments === []) {
            return [];
        }

        $shape = new self($inputSchema);
        $candidates = $shape->candidates($inputSchema, 0);

        $out = [];
        foreach ($arguments as $key => $value) {
            $out[$key] = $shape->value($value, $shape->propertySchema($candidates, (string) $key), 1);
        }

        return $out;
    }

    private function value(mixed $value, mixed $schema, int $depth): mixed
    {
        if (!is_array($value) || $depth > self::MAX_DEPTH) {
            // Scalars, null, and a caller-built \stdClass are already exact.
            return $value;
        }

        if ($value === []) {
            $admits = $this->admits($schema, $depth);

            return $admits !== null && $admits['object'] && !$admits['array'] ? new \stdClass() : [];
        }

        $candidates = $this->candidates($schema, $depth);
        $admits = $this->admits($schema, $depth);
        $asList = array_is_list($value) && ($admits === null || $admits['array']);

        $out = [];
        foreach ($value as $key => $member) {
            $memberSchema = $asList
                ? $this->itemSchema($candidates, (int) $key)
                : $this->propertySchema($candidates, (string) $key);
            $out[$key] = $this->value($member, $memberSchema, $depth + 1);
        }

        return $out;
    }

    /**
     * Which of object/array the schema admits, or null when it does not say.
     *
     * @return array{object: bool, array: bool}|null
     */
    private function admits(mixed $schema, int $depth): ?array
    {
        $schema = $this->resolve($schema);
        if (!is_array($schema) || $depth > self::MAX_DEPTH || ++$this->visits > self::VISIT_BUDGET) {
            return null;
        }

        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            $type = [$type];
        }
        if (is_array($type) && $type !== []) {
            return ['object' => in_array('object', $type, true), 'array' => in_array('array', $type, true)];
        }

        $object = self::hasAnyKey($schema, self::OBJECT_KEYWORDS);
        $array = self::hasAnyKey($schema, self::ARRAY_KEYWORDS);
        if ($object || $array) {
            return ['object' => $object, 'array' => $array];
        }

        if (is_array($schema['allOf'] ?? null)) {
            $known = null;
            foreach ($schema['allOf'] as $branch) {
                $a = $this->admits($branch, $depth + 1);
                if ($a === null) {
                    continue;
                }
                $known = $known === null
                    ? $a
                    : ['object' => $known['object'] && $a['object'], 'array' => $known['array'] && $a['array']];
            }
            if ($known !== null) {
                return $known;
            }
        }

        foreach (['anyOf', 'oneOf'] as $union) {
            if (!is_array($schema[$union] ?? null) || $schema[$union] === []) {
                continue;
            }
            $any = ['object' => false, 'array' => false];
            foreach ($schema[$union] as $branch) {
                $a = $this->admits($branch, $depth + 1);
                if ($a === null) {
                    // A branch that says nothing accepts `[]` as-is.
                    return null;
                }
                $any = ['object' => $any['object'] || $a['object'], 'array' => $any['array'] || $a['array']];
            }

            return $any;
        }

        return null;
    }

    /**
     * The schema plus every `allOf`/`anyOf`/`oneOf` branch below it, `$ref`s
     * resolved — the places a member's own schema may be declared.
     *
     * @return list<array<array-key,mixed>>
     */
    private function candidates(mixed $schema, int $depth): array
    {
        $schema = $this->resolve($schema);
        if (!is_array($schema) || $depth > self::MAX_DEPTH || ++$this->visits > self::VISIT_BUDGET) {
            return [];
        }

        $found = [$schema];
        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            if (!is_array($schema[$combinator] ?? null)) {
                continue;
            }
            foreach ($schema[$combinator] as $branch) {
                array_push($found, ...$this->candidates($branch, $depth + 1));
            }
        }

        return $found;
    }

    /** @param list<array<array-key,mixed>> $candidates */
    private function propertySchema(array $candidates, string $key): mixed
    {
        foreach ($candidates as $schema) {
            $properties = $schema['properties'] ?? null;
            if (is_array($properties) && array_key_exists($key, $properties)) {
                return $properties[$key];
            }
        }

        foreach ($candidates as $schema) {
            $patterns = $schema['patternProperties'] ?? null;
            if (!is_array($patterns)) {
                continue;
            }
            foreach ($patterns as $pattern => $sub) {
                // ECMA-262 patterns; one PCRE cannot compile is simply no match.
                if (@preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', $key) === 1) {
                    return $sub;
                }
            }
        }

        foreach ($candidates as $schema) {
            if (is_array($schema['additionalProperties'] ?? null)) {
                return $schema['additionalProperties'];
            }
        }

        return null;
    }

    /** @param list<array<array-key,mixed>> $candidates */
    private function itemSchema(array $candidates, int $index): mixed
    {
        foreach ($candidates as $schema) {
            $prefix = $schema['prefixItems'] ?? null;
            if (is_array($prefix) && array_is_list($prefix) && array_key_exists($index, $prefix)) {
                return $prefix[$index];
            }

            $items = $schema['items'] ?? null;
            if (is_array($items) && $items !== [] && array_is_list($items)) {
                // Draft-4 tuple form: `items` is a list of positional schemas.
                if (array_key_exists($index, $items)) {
                    return $items[$index];
                }
                if (is_array($schema['additionalItems'] ?? null)) {
                    return $schema['additionalItems'];
                }
                continue;
            }

            if (is_array($items) && $items !== []) {
                return $items;
            }
        }

        return null;
    }

    /**
     * Follow local `$ref`s (`#/…` JSON pointers into the root schema). Sibling
     * keywords next to a `$ref` win over the target's (2019-09 semantics);
     * an unresolvable or remote ref leaves the schema as written.
     */
    private function resolve(mixed $schema): mixed
    {
        for ($hops = 0; $hops < 16 && is_array($schema) && is_string($schema['$ref'] ?? null); $hops++) {
            $target = $this->pointer($schema['$ref']);
            if (!is_array($target)) {
                return $schema;
            }
            $siblings = $schema;
            unset($siblings['$ref']);
            $schema = $siblings + $target;
        }

        return $schema;
    }

    private function pointer(string $ref): mixed
    {
        if ($ref === '#') {
            return $this->root;
        }
        if (!str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<array-key,mixed> $schema
     * @param list<string> $keys
     */
    private static function hasAnyKey(array $schema, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }
}
