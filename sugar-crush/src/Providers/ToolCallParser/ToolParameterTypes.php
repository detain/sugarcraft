<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

use SugarCraft\Crush\Tools\Tool;

/**
 * The JSON-Schema `type` each offered tool declares for each of its
 * top-level parameters - tool name => parameter name => list of type names.
 *
 * Exists for text-scanning parsers whose markup carries NO type information
 * ({@see MinimaxXmlFallbackToolCallParser}): every parameter value arrives as
 * raw text, so without the declared type a parser can only guess, and a guess
 * of "JSON-looking text is JSON" hands `Write` a PHP array where it declared
 * the `content` of a composer.json to be a string (audit 15a A9). Built ONCE
 * per request from `CompleteRequest::$tools` by the provider, then handed to
 * the parser through {@see ToolSchemaAware::withParameterTypes()}.
 *
 * Read from the tool's raw `inputSchema()`, not the wire-normalised copy:
 * `ToolSchema::normalizeToolSchema()` only rewrites an empty `properties` to
 * `{}`, which changes no `type`, so the two agree on every entry read here.
 *
 * UNKNOWN IS A FIRST-CLASS ANSWER. A parameter whose schema declares no type
 * - or a `anyOf`/`oneOf` with a branch that declares none - reports null
 * rather than a partial list, because the caller's conservative fallback
 * (keep the raw text) is only correct when "unknown" is never mistaken for
 * "declared".
 */
final readonly class ToolParameterTypes
{
    /**
     * @param array<string, array<string, list<string>>> $types
     */
    private function __construct(private array $types) {}

    /**
     * Default root factory, per repo convention.
     *
     * @param array<string, array<string, list<string>>> $types
     */
    public static function new(array $types = []): self
    {
        $clean = [];

        foreach ($types as $tool => $parameters) {
            if (!is_string($tool) || !is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter => $declared) {
                $list = is_array($declared) ? self::stringList($declared) : null;

                if (is_string($parameter) && $list !== null) {
                    $clean[$tool][$parameter] = $list;
                }
            }
        }

        return new self($clean);
    }

    /**
     * Built from the request's offered tools. Entries that are not a
     * {@see Tool} are skipped rather than rejected: `CompleteRequest::$tools`
     * is typed `array<mixed>`, and a malformed entry must cost type
     * information for that one tool, never the whole request.
     *
     * @param ?array<mixed> $tools
     */
    public static function fromTools(?array $tools): self
    {
        $types = [];

        foreach ($tools ?? [] as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            $properties = $tool->inputSchema()['properties'] ?? null;

            if (!is_array($properties)) {
                continue;
            }

            foreach ($properties as $parameter => $node) {
                $declared = is_string($parameter) && is_array($node) ? self::typesOf($node) : null;

                if ($declared !== null) {
                    $types[$tool->name()][$parameter] = $declared;
                }
            }
        }

        return new self($types);
    }

    /**
     * The declared type names, or null when the tool, the parameter or its
     * type is unknown.
     *
     * @return list<string>|null
     */
    public function declared(string $tool, string $parameter): ?array
    {
        return $this->types[$tool][$parameter] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }

    /**
     * @param array<mixed> $node
     * @return list<string>|null
     */
    private static function typesOf(array $node): ?array
    {
        if (array_key_exists('type', $node)) {
            return is_string($node['type'])
                ? [$node['type']]
                : (is_array($node['type']) ? self::stringList($node['type']) : null);
        }

        // One level of `anyOf`/`oneOf` covers the common nullable spelling
        // (`anyOf: [{type: string}, {type: null}]`). Deeper composition is
        // left unknown on purpose - see the class docblock.
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!is_array($node[$keyword] ?? null) || $node[$keyword] === []) {
                continue;
            }

            $union = [];

            foreach ($node[$keyword] as $branch) {
                $branchTypes = is_array($branch) && array_key_exists('type', $branch)
                    ? self::typesOf(['type' => $branch['type']])
                    : null;

                if ($branchTypes === null) {
                    return null;
                }

                $union = [...$union, ...$branchTypes];
            }

            return array_values(array_unique($union));
        }

        return null;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>|null Null when any member is not a non-empty string.
     */
    private static function stringList(array $values): ?array
    {
        $list = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return null;
            }

            $list[] = $value;
        }

        return $list === [] ? null : array_values(array_unique($list));
    }
}
